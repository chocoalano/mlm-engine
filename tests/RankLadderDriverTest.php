<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\PlanComponent;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Planning\PlanComponentDefinition;
use PandaBear\Mlm\Planning\PlanComponentDriverRegistry;
use PandaBear\Mlm\Planning\PlanRuleDefinition;
use PandaBear\Mlm\Planning\PlanVersionStatus;
use PandaBear\Mlm\Planning\Rules\MetricCondition;
use PandaBear\Mlm\Planning\Rules\RuleDefinition;
use PandaBear\Mlm\Rank\RankContext;
use PandaBear\Mlm\Rank\RankEngine;
use PandaBear\Mlm\Rank\RankLadderDriver;
use PandaBear\Mlm\Tests\Concerns\BuildsPlanDefinitions;

/**
 * The built-in rank ladder, as a plan definition: built with the editor,
 * judged when its version is validated, copied by the generic cloner — no
 * rank-specific storage, editor or cloner.
 */
final class RankLadderDriverTest extends DatabaseTestCase
{
    use BuildsPlanDefinitions;

    public function test_a_ladder_validates_with_nothing_registered_by_the_application(): void
    {
        $version = $this->draft();
        $this->addLadder($version, $this->ranks(bronze: 10, silver: 20, gold: 30));

        $this->assertSame(PlanVersionStatus::Validated, $this->lifecycle()->markValidated($version)->status);
    }

    public function test_a_validated_ladder_can_be_evaluated(): void
    {
        $ladder = $this->validatedLadder($this->ranks(bronze: 10, silver: 20, gold: 30));
        $member = Member::factory()->for($ladder->planVersion->plan->program)->create();

        $decision = $this->app->make(RankEngine::class)->evaluate($ladder, new RankContext($member));

        $this->assertSame('bronze', $decision->selectedRank?->key);
        $this->assertSame(['bronze', 'silver', 'gold'], array_column($decision->toArray()['ranks'], 'key'));
    }

    public function test_an_empty_ladder_can_be_drafted_but_not_validated(): void
    {
        $version = $this->draft();
        $this->editor()->addComponent($version, 'career-ranks', 'rank.ladder', 'Career Ranks');

        $this->assertValidationRefused($version, 'component "career-ranks", driver "rank.ladder": Invalid plan definition rank ladder: a rank ladder needs at least one rank, but it has no rules.');
    }

    public function test_a_ladder_takes_no_parameters(): void
    {
        $version = $this->draft();
        $ladder = $this->editor()->addComponent($version, 'career-ranks', 'rank.ladder', 'Career Ranks', ['strategy' => 'highest', 'demotion' => false]);
        $this->editor()->addRule($ladder, 'bronze', 'Bronze', $this->requirement(), 10);

        $this->assertValidationRefused($version, 'driver "rank.ladder": Invalid plan definition rank ladder: a rank ladder takes no parameters, but it has "demotion", "strategy".');

        $this->editor()->updateComponent($ladder, parameters: []);
        $this->assertSame(PlanVersionStatus::Validated, $this->lifecycle()->markValidated($version)->status);
    }

    public function test_ranks_sharing_a_position_can_be_drafted_but_not_validated(): void
    {
        $version = $this->draft();
        $ladder = $this->addLadder($version, $this->ranks(bronze: 10, silver: 20, gold: 20));

        $this->assertValidationRefused($version, 'driver "rank.ladder": Invalid plan definition rank ladder: ranks "silver" and "gold" share position 20; every rank of a ladder needs a position of its own.');

        $this->editor()->updateRule($ladder->rules()->where('key', 'gold')->sole(), position: 30);
        $this->assertSame(PlanVersionStatus::Validated, $this->lifecycle()->markValidated($version)->status);
    }

    public function test_positions_may_have_gaps(): void
    {
        $version = $this->draft();
        $this->addLadder($version, $this->ranks(bronze: 10, silver: 50, gold: 999));

        $this->assertSame(PlanVersionStatus::Validated, $this->lifecycle()->markValidated($version)->status);
    }

    public function test_the_driver_judges_only_rank_ladders(): void
    {
        $this->expectException(InvalidPlanDefinition::class);
        $this->expectExceptionMessage('its driver is "acme.example", not "rank.ladder".');

        (new RankLadderDriver)->validate(new PlanComponentDefinition('bonus', 'acme.example', 'Bonus', [], 1, [
            new PlanRuleDefinition('bronze', 'Bronze', 10, $this->requirement()),
        ]));
    }

    public function test_rank_requirements_are_checked_by_the_generic_validator(): void
    {
        $version = $this->draft();
        $this->addLadder($version, ['bronze' => [10, RuleDefinition::all(MetricCondition::of('acme.missing', [], '>=', '1'))]]);

        $this->assertValidationRefused($version, 'component "career-ranks", rule "bronze", root.children[0], metric "acme.missing": no metric is registered under this key.');
    }

    public function test_application_drivers_work_beside_the_rank_ladder(): void
    {
        $this->criteriaDriver();
        $version = $this->draft();
        $criteria = $this->editor()->addComponent($version, 'entry', 'test.criteria', 'Entry criteria', ['mode' => 'strict']);
        $this->editor()->addRule($criteria, 'active', 'Active', $this->requirement());
        $this->addLadder($version, $this->ranks(bronze: 10, silver: 20));

        $this->assertSame(PlanVersionStatus::Validated, $this->lifecycle()->markValidated($version)->status);
        $this->assertSame(['rank.ladder', 'test.criteria'], $this->app->make(PlanComponentDriverRegistry::class)->keys());
        $this->assertSame(['entry'], array_map(static fn (PlanComponentDefinition $component): string => $component->key, $this->criteriaDriver()->validated));
    }

    public function test_the_generic_cloner_copies_a_ladder_into_a_new_draft(): void
    {
        $ladder = $this->validatedLadder($this->ranks(bronze: 10, silver: 50, gold: 999));
        $source = $ladder->planVersion;

        $draft = $this->cloner()->cloneToNewDraft($source);
        $copy = $draft->components()->sole();

        $this->assertSame(PlanVersionStatus::Draft, $draft->status);
        $this->assertSame($source->version + 1, $draft->version);
        $this->assertSame($this->storedDefinition($source), $this->storedDefinition($draft));
        $this->assertSame([['bronze', 10], ['silver', 50], ['gold', 999]], $copy->rules->map(static fn ($rule): array => [$rule->key, $rule->position])->all());
        $this->assertNotSame($ladder->id, $copy->id);
        $this->assertSame([], array_intersect($ladder->rules->modelKeys(), $copy->rules->modelKeys()));

        // The copy is a ladder like any other: validated, then evaluated.
        $this->lifecycle()->markValidated($draft);
        $member = Member::factory()->for($source->plan->program)->create();

        $this->assertSame(
            $this->app->make(RankEngine::class)->evaluate($ladder, new RankContext($member))->toArray()['ranks'],
            $this->app->make(RankEngine::class)->evaluate(PlanComponent::query()->findOrFail($copy->id), new RankContext($member))->toArray()['ranks'],
        );
    }

    /**
     * Ranks at these positions — the lowest reached by any member, the
     * others out of reach.
     *
     * @return array<string, array{int, RuleDefinition}>
     */
    private function ranks(int ...$positions): array
    {
        $ranks = [];

        foreach ($positions as $key => $position) {
            $ranks[$key] = [$position, $this->requirement($ranks === [] ? '0' : '1000000')];
        }

        return $ranks;
    }

    private function requirement(string $volume = '0'): RuleDefinition
    {
        return RuleDefinition::all(MetricCondition::of('member.volume', ['type' => 'sales'], '>=', $volume));
    }

    private function assertValidationRefused(PlanVersion $version, string $message): void
    {
        try {
            $this->lifecycle()->markValidated($version);
            $this->fail('An invalid rank ladder was validated.');
        } catch (InvalidPlanDefinition $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }

        $this->assertSame(PlanVersionStatus::Draft, $version->refresh()->status);
    }
}
