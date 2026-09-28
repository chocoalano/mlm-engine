<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\PlanComponent;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Planning\PlanVersionStatus;
use PandaBear\Mlm\Planning\Rules\MetricCondition;
use PandaBear\Mlm\Planning\Rules\RuleDefinition;
use PandaBear\Mlm\Rank\RankContext;
use PandaBear\Mlm\Rank\RankDecision;
use PandaBear\Mlm\Rank\RankEngine;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;
use PandaBear\Mlm\Tests\Concerns\BuildsPlanDefinitions;
use PandaBear\Mlm\Tests\Concerns\RecordsVolume;

/**
 * Ranks over the built-in metrics and real records: a rank is derived from
 * the ladder and range it is evaluated with — history and reversals included,
 * as the metrics resolve them — and never stored.
 */
final class RankMetricsTest extends DatabaseTestCase
{
    use BuildsGenealogies;
    use BuildsPlanDefinitions;
    use RecordsVolume;

    public function test_a_ladder_over_all_three_built_in_metrics(): void
    {
        $program = Program::factory()->create();
        $members = $this->members($program, 'Alice', 'Bob', 'Charlie');
        $this->link('sponsor', $members, '2026-01-01 00:00:00', 'Alice', 'Bob');
        $this->link('placement', $members, '2026-01-01 00:00:00', 'Alice', 'Charlie');

        $this->record($members['Alice'], '120', 'alice', at: $this->at('2026-02-01 00:00:00'));
        $this->record($members['Bob'], '250', 'bob', at: $this->at('2026-02-01 00:00:00'));
        $this->record($members['Charlie'], '299.5', 'charlie', at: $this->at('2026-02-01 00:00:00'));

        $own = MetricCondition::of('member.volume', ['type' => 'sales'], '>=', '100');
        $sponsored = MetricCondition::of('sponsor.network.volume', ['type' => 'sales'], '>=', '200');
        $placed = MetricCondition::of('placement.network.volume', ['type' => 'sales', 'max_depth' => 1], '>=', '300');
        $ladder = $this->ladderIn($program, [
            'bronze' => [10, RuleDefinition::all($own)],
            'silver' => [20, RuleDefinition::all($own, $sponsored)],
            'gold' => [30, RuleDefinition::all($own, $sponsored, $placed)],
        ]);

        $decision = $this->evaluate($ladder, $members['Alice']);

        $this->assertSame('silver', $decision->selectedRank?->key);
        $this->assertSame([
            'bronze' => [['member.volume', '120', true]],
            'silver' => [['member.volume', '120', true], ['sponsor.network.volume', '250', true]],
            'gold' => [['member.volume', '120', true], ['sponsor.network.volume', '250', true], ['placement.network.volume', '299.5', false]],
        ], $this->conditions($decision));

        $this->record($members['Charlie'], '0.5', 'charlie-more', at: $this->at('2026-02-02 00:00:00'));

        $this->assertSame('gold', $this->evaluate($ladder, $members['Alice'])->selectedRank?->key);
    }

    public function test_the_rank_follows_the_range_it_is_evaluated_over(): void
    {
        $program = Program::factory()->create();
        $members = $this->members($program, 'Alice', 'Bob');

        // January: Alice sells; Bob sells while not yet hers. February: Alice
        // sponsors Bob. April: Bob sells again, Alice a little.
        $this->record($members['Alice'], '150', 'alice-jan', at: $this->at('2026-01-10 00:00:00'));
        $this->record($members['Bob'], '500', 'bob-jan', at: $this->at('2026-01-15 00:00:00'));
        $this->link('sponsor', $members, '2026-02-01 00:00:00', 'Alice', 'Bob');
        $this->record($members['Bob'], '300', 'bob-apr', at: $this->at('2026-04-10 00:00:00'));
        $this->record($members['Alice'], '20', 'alice-apr', at: $this->at('2026-04-12 00:00:00'));

        $ladder = $this->ladderIn($program, [
            'bronze' => [10, RuleDefinition::all(MetricCondition::of('member.volume', ['type' => 'sales'], '>=', '100'))],
            'silver' => [20, RuleDefinition::all(MetricCondition::of('sponsor.network.volume', ['type' => 'sales'], '>=', '250'))],
        ]);

        $january = $this->evaluate($ladder, $members['Alice'], '2026-01-01 00:00:00', '2026-02-01 00:00:00');
        $march = $this->evaluate($ladder, $members['Alice'], '2026-03-01 00:00:00', '2026-04-01 00:00:00');
        $april = $this->evaluate($ladder, $members['Alice'], '2026-04-01 00:00:00', '2026-05-01 00:00:00');

        $this->assertSame(['bronze' => [['member.volume', '150', true]], 'silver' => [['sponsor.network.volume', '0', false]]], $this->conditions($january));
        $this->assertSame('bronze', $january->selectedRank?->key);
        $this->assertNull($march->selectedRank);
        $this->assertSame(['bronze' => [['member.volume', '20', false]], 'silver' => [['sponsor.network.volume', '300', true]]], $this->conditions($april));
        $this->assertSame('silver', $april->selectedRank?->key);
        $this->assertSame('silver', $this->evaluate($ladder, $members['Alice'])->selectedRank?->key);
    }

    public function test_a_reversal_changes_the_rank_of_the_range_it_falls_in(): void
    {
        $program = Program::factory()->create();
        $members = $this->members($program, 'Alice', 'Bob', 'Charlie');
        $this->link('placement', $members, '2026-01-01 00:00:00', 'Bob', 'Charlie');

        $sale = $this->record($members['Charlie'], '100', 'jan', at: $this->at('2026-01-15 00:00:00'));
        // Alice joins above Bob in March; the January sale is refunded in April.
        $this->link('placement', $members, '2026-03-01 00:00:00', 'Alice', 'Bob');
        $this->reverse($sale, 'jan-refund', at: $this->at('2026-04-10 00:00:00'));

        $ladder = $this->ladderIn($program, [
            'bronze' => [10, RuleDefinition::all(MetricCondition::of('placement.network.volume', ['type' => 'sales'], '>=', '1'))],
            'silver' => [20, RuleDefinition::all(MetricCondition::of('placement.network.volume', ['type' => 'sales'], '>=', '100'))],
        ]);

        $january = ['2026-01-01 00:00:00', '2026-02-01 00:00:00'];
        $april = ['2026-04-01 00:00:00', '2026-05-01 00:00:00'];

        $this->assertSame('silver', $this->evaluate($ladder, $members['Bob'], ...$january)->selectedRank?->key);
        $this->assertSame(['bronze' => [['placement.network.volume', '-100', false]], 'silver' => [['placement.network.volume', '-100', false]]], $this->conditions($this->evaluate($ladder, $members['Bob'], ...$april)));
        $this->assertNull($this->evaluate($ladder, $members['Bob'], ...$april)->selectedRank);
        $this->assertNull($this->evaluate($ladder, $members['Bob'])->selectedRank);

        // Alice received neither the sale nor its refund.
        $this->assertNull($this->evaluate($ladder, $members['Alice'], ...$january)->selectedRank);
        $this->assertSame(['bronze' => [['placement.network.volume', '0', false]], 'silver' => [['placement.network.volume', '0', false]]], $this->conditions($this->evaluate($ladder, $members['Alice'], ...$april)));
    }

    public function test_a_superseded_or_archived_ladder_is_evaluated_as_it_was(): void
    {
        $program = Program::factory()->create();
        $alice = Member::factory()->for($program)->create();
        $this->record($alice, '600', 'alice', at: $this->at('2026-02-01 00:00:00'));

        $first = $this->ladderIn($program, [
            'bronze' => [10, RuleDefinition::all(MetricCondition::of('member.volume', ['type' => 'sales'], '>=', '100'))],
            'gold' => [30, RuleDefinition::all(MetricCondition::of('member.volume', ['type' => 'sales'], '>=', '500'))],
        ]);
        $lifecycle = $this->lifecycle();
        $lifecycle->activate($lifecycle->publish($first->planVersion));
        $asActive = $this->evaluate($first, $alice)->toArray();

        // Version 2 raises gold, and replaces version 1.
        $draft = $this->cloner()->cloneToNewDraft($first->planVersion);
        $second = $draft->components()->sole();
        $this->editor()->updateRule($second->rules()->where('key', 'gold')->sole(), definition: RuleDefinition::all(MetricCondition::of('member.volume', ['type' => 'sales'], '>=', '1000')));
        $lifecycle->activate($lifecycle->publish($lifecycle->markValidated($draft)));

        $this->assertSame(PlanVersionStatus::Superseded, $first->planVersion->refresh()->status);
        $asSuperseded = $this->evaluate($first, $alice);
        $this->assertSame(['gold', 1], [$asSuperseded->selectedRank?->key, $asSuperseded->planVersion]);
        $this->assertSame($asActive, $asSuperseded->toArray());

        $current = $this->evaluate(PlanComponent::query()->findOrFail($second->id), $alice);
        $this->assertSame(['bronze', 2], [$current->selectedRank?->key, $current->planVersion]);

        $lifecycle->archive($first->planVersion);

        $this->assertSame(PlanVersionStatus::Archived, $first->planVersion->refresh()->status);
        $this->assertSame($asActive, $this->evaluate($first, $alice)->toArray());
    }

    /**
     * @param  array<string, array{int, RuleDefinition}>  $ranks
     */
    private function ladderIn(Program $program, array $ranks): PlanComponent
    {
        return $this->validatedLadder($ranks, $program->plans()->create(['code' => 'MAIN', 'name' => 'Main']));
    }

    private function evaluate(PlanComponent $ladder, Member $member, ?string $from = null, ?string $until = null): RankDecision
    {
        return $this->app->make(RankEngine::class)->evaluate($ladder, new RankContext(
            $member,
            $from === null ? null : CarbonImmutable::parse($from),
            $until === null ? null : CarbonImmutable::parse($until),
        ));
    }

    /**
     * @return array<string, list<array{string, string, bool}>> metric, value, outcome of each top-level condition, by rank
     */
    private function conditions(RankDecision $decision): array
    {
        $conditions = [];

        foreach ($decision->toArray()['ranks'] as $rank) {
            $conditions[$rank['key']] = array_map(
                static fn (array $node): array => [$node['metric'], $node['value'], $node['passed']],
                $rank['trace']['children'],
            );
        }

        return $conditions;
    }

    /**
     * @param  'sponsor'|'placement'  $tree
     * @param  array<string, Member>  $members
     */
    private function link(string $tree, array $members, string $at, string $parent, string $child): void
    {
        $this->travelTo(CarbonImmutable::parse($at));

        $tree === 'sponsor'
            ? $this->genealogy()->assignSponsor($members[$child], $members[$parent])
            : $this->placement()->place($members[$child], $members[$parent]);
    }

    private function at(string $moment): CarbonImmutable
    {
        return CarbonImmutable::parse($moment);
    }
}
