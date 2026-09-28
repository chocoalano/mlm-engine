<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Exceptions\InvalidRuleDefinition;
use PandaBear\Mlm\Exceptions\QualificationEvaluationException;
use PandaBear\Mlm\Exceptions\RankEvaluationException;
use PandaBear\Mlm\Exceptions\UnknownMetric;
use PandaBear\Mlm\Metrics\MetricRegistry;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanComponent;
use PandaBear\Mlm\Models\PlanRule;
use PandaBear\Mlm\Planning\PlanVersionStatus;
use PandaBear\Mlm\Planning\Rules\MetricCondition;
use PandaBear\Mlm\Planning\Rules\RuleDefinition;
use PandaBear\Mlm\Qualification\QualificationContext;
use PandaBear\Mlm\Qualification\QualificationEngine;
use PandaBear\Mlm\Rank\RankContext;
use PandaBear\Mlm\Rank\RankDecision;
use PandaBear\Mlm\Rank\RankEngine;
use PandaBear\Mlm\Tests\Concerns\BuildsPlanDefinitions;
use PandaBear\Mlm\Tests\Fixtures\CountedMetric;
use PandaBear\Mlm\Tests\Fixtures\FailingMetric;
use PandaBear\Mlm\Tests\Fixtures\ScoreMetric;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * One stored rank ladder, chosen by the caller, evaluated for one member:
 * every rank qualified through the qualification engine, the highest
 * qualifying position selected, and "cannot evaluate" never confused with
 * "no rank".
 */
final class RankEngineTest extends DatabaseTestCase
{
    use BuildsPlanDefinitions;

    /**
     * Which of bronze (10), silver (20) and gold (30) qualify, and the rank
     * selected.
     *
     * @return array<string, array{bool, bool, bool, string|null}>
     */
    public static function outcomes(): array
    {
        return [
            'none' => [false, false, false, null],
            'the lowest only' => [true, false, false, 'bronze'],
            'the middle only' => [false, true, false, 'silver'],
            'the two lowest' => [true, true, false, 'silver'],
            'all' => [true, true, true, 'gold'],
            'the highest only: lower ranks are not required' => [false, false, true, 'gold'],
            'the highest over a failing middle' => [true, false, true, 'gold'],
        ];
    }

    #[DataProvider('outcomes')]
    public function test_the_highest_qualifying_rank_is_selected(bool $bronze, bool $silver, bool $gold, ?string $selected): void
    {
        [$ladder, $member] = $this->ladderAndMember([
            'bronze' => [10, $this->reaches($bronze, 'bronze')],
            'silver' => [20, $this->reaches($silver, 'silver')],
            'gold' => [30, $this->reaches($gold, 'gold')],
        ]);

        $decision = $this->evaluate($ladder, $member);

        $this->assertSame($selected, $decision->selectedRank?->key);
        $this->assertSame(
            [['bronze', 10, $bronze], ['silver', 20, $silver], ['gold', 30, $gold]],
            array_map(static fn (array $rank): array => [$rank['key'], $rank['position'], $rank['qualified']], $decision->toArray()['ranks']),
        );
    }

    public function test_no_qualifying_rank_is_a_decision_not_an_error(): void
    {
        [$ladder, $member] = $this->ladderAndMember([
            'bronze' => [10, $this->reaches(false, 'bronze')],
            'silver' => [20, $this->reaches(false, 'silver')],
        ]);

        $decision = $this->evaluate($ladder, $member);

        $this->assertNull($decision->selectedRank);
        $this->assertNull($decision->toArray()['selected_rank']);
        $this->assertCount(2, $decision->ranks);
    }

    /**
     * @return array<string, array{list<bool>}>
     */
    public static function ladders(): array
    {
        return [
            'every rank qualifies' => [[true, true, true]],
            'the lowest fails' => [[false, true, true]],
            'the highest qualifies, the rest fail' => [[false, false, true]],
            'the lowest qualifies, the rest fail' => [[true, false, false]],
        ];
    }

    /**
     * @param  list<bool>  $outcomes
     */
    #[DataProvider('ladders')]
    public function test_every_rank_is_evaluated_lowest_first_whatever_the_others_give(array $outcomes): void
    {
        [$ladder, $member] = $this->ladderAndMember([
            'silver' => [20, $this->reaches($outcomes[1], 'silver')],
            'gold' => [30, $this->reaches($outcomes[2], 'gold')],
            'bronze' => [10, $this->reaches($outcomes[0], 'bronze')],
        ]);

        $this->evaluate($ladder, $member);

        $this->assertSame(['bronze', 'silver', 'gold'], array_column($this->countedMetric()->resolutions, 'label'));
    }

    public function test_positions_order_and_select_ranks_not_keys_names_or_ids(): void
    {
        // Added highest first; keys and names sort the other way round.
        [$ladder, $member] = $this->ladderAndMember([
            'alpha' => [999, $this->reaches(true, 'alpha')],
            'zinc' => [10, $this->reaches(true, 'zinc')],
            'mid' => [50, $this->reaches(true, 'mid')],
        ]);

        $decision = $this->evaluate($ladder, $member);

        $this->assertSame(['key' => 'alpha', 'name' => 'Alpha', 'position' => 999], $decision->toArray()['selected_rank']);
        $this->assertSame(['zinc', 'mid', 'alpha'], array_column($decision->toArray()['ranks'], 'key'));
    }

    public function test_the_decision_names_its_ladder_and_explains_every_rank(): void
    {
        $this->countedMetric();
        $plan = Plan::factory()->create(['code' => 'MAIN']);
        $ladder = $this->validatedLadder([
            'bronze' => [10, RuleDefinition::all($this->counted('150', '>=', '100'))],
            'silver' => [20, RuleDefinition::any($this->counted('150', '>=', '500'), $this->counted('3', 'in', '1', '2'))],
        ], $plan);
        $member = Member::factory()->for($plan->program)->create(['member_code' => 'M-001']);

        $decision = $this->evaluate($ladder, $member, '2026-03-01 00:00:00', '2026-04-01 00:00:00');
        $rules = PlanRule::query()->where('plan_component_id', $ladder->id)->orderBy('position')->get();
        $qualification = fn (PlanRule $rule): array => $this->app->make(QualificationEngine::class)
            ->evaluate($rule, new QualificationContext($member, CarbonImmutable::parse('2026-03-01 00:00:00'), CarbonImmutable::parse('2026-04-01 00:00:00')))
            ->toArray()['trace'];

        $this->assertSame([
            'program' => ['id' => $plan->program->id, 'code' => $plan->program->code],
            'plan' => ['id' => $plan->id, 'code' => 'MAIN'],
            'plan_version' => ['id' => $ladder->plan_version_id, 'version' => 1],
            'component' => ['key' => 'career-ranks', 'name' => 'Career Ranks'],
            'member' => ['id' => $member->id, 'member_code' => 'M-001'],
            'from' => '2026-03-01 00:00:00',
            'until' => '2026-04-01 00:00:00',
            'selected_rank' => ['key' => 'bronze', 'name' => 'Bronze', 'position' => 10],
            'ranks' => [
                ['key' => 'bronze', 'name' => 'Bronze', 'position' => 10, 'qualified' => true, 'trace' => $qualification($rules[0])],
                ['key' => 'silver', 'name' => 'Silver', 'position' => 20, 'qualified' => false, 'trace' => $qualification($rules[1])],
            ],
        ], $decision->toArray());

        $this->assertSame([
            'type' => 'group',
            'path' => 'root',
            'match' => 'any',
            'passed' => false,
            'children' => [
                ['type' => 'condition', 'path' => 'root.children[0]', 'metric' => 'test.counted', 'parameters' => ['value' => '150'], 'value' => '150', 'operator' => '>=', 'operands' => ['500'], 'passed' => false],
                ['type' => 'condition', 'path' => 'root.children[1]', 'metric' => 'test.counted', 'parameters' => ['value' => '3'], 'value' => '3', 'operator' => 'in', 'operands' => ['1', '2'], 'passed' => false],
            ],
        ], $decision->toArray()['ranks'][1]['trace']);
    }

    public function test_the_same_stored_state_gives_the_same_decision_whatever_the_clock(): void
    {
        [$ladder, $member] = $this->ladderAndMember([
            'bronze' => [10, $this->reaches(true, 'bronze')],
            'silver' => [20, $this->reaches(false, 'silver')],
        ]);

        $this->travelTo(CarbonImmutable::parse('2026-01-01 00:00:00'));
        $first = $this->evaluate($ladder, $member, '2026-03-01 00:00:00')->toArray();
        $this->travelTo(CarbonImmutable::parse('2031-12-31 23:59:59'));
        $second = $this->evaluate($ladder, $member, '2026-03-01 00:00:00')->toArray();

        $this->assertSame($first, $second);
        $this->assertArrayNotHasKey('evaluated_at', $first);
    }

    public function test_only_the_chosen_ladder_is_evaluated(): void
    {
        $this->countedMetric();
        $version = $this->draft();
        $career = $this->addLadder($version, ['bronze' => [10, $this->reaches(true, 'bronze')], 'gold' => [30, $this->reaches(true, 'gold')]]);
        $leadership = $this->addLadder($version, ['mentor' => [10, $this->reaches(true, 'mentor')], 'director' => [20, $this->reaches(false, 'director')]], 'leadership-ranks', 'Leadership Ranks');
        $this->lifecycle()->markValidated($version);
        $member = Member::factory()->for($version->plan->program)->create();

        $decision = $this->evaluate($career, $member);

        $this->assertSame(['career-ranks', 'gold'], [$decision->componentKey, $decision->selectedRank?->key]);
        $this->assertSame(['bronze', 'gold'], array_column($decision->toArray()['ranks'], 'key'));
        $this->assertSame(['bronze', 'gold'], array_column($this->countedMetric()->resolutions, 'label'));

        $this->assertSame('mentor', $this->evaluate($leadership, $member)->selectedRank?->key);
    }

    /**
     * @return array<string, array{PlanVersionStatus}>
     */
    public static function evaluableStatuses(): array
    {
        return [
            'validated' => [PlanVersionStatus::Validated],
            'published' => [PlanVersionStatus::Published],
            'active' => [PlanVersionStatus::Active],
            'superseded' => [PlanVersionStatus::Superseded],
            'archived' => [PlanVersionStatus::Archived],
        ];
    }

    #[DataProvider('evaluableStatuses')]
    public function test_a_ladder_of_any_validated_version_can_be_evaluated(PlanVersionStatus $status): void
    {
        $ladder = $this->ladderIn($status);
        $member = Member::factory()->for($ladder->planVersion->plan->program)->create();

        $decision = $this->evaluate($ladder, $member);

        $this->assertSame($status, $ladder->planVersion->refresh()->status);
        $this->assertSame($ladder->plan_version_id, $decision->planVersionId);
        $this->assertSame('silver', $decision->selectedRank?->key);
    }

    public function test_a_draft_ladder_is_refused_however_valid_its_ranks(): void
    {
        $this->countedMetric();
        $ladder = $this->addLadder($this->draft(), ['bronze' => [10, $this->reaches(true, 'bronze')]]);

        $this->assertRefused($ladder, $this->memberOf($ladder), 'component "career-ranks": its plan version is a draft');
    }

    public function test_a_draft_stays_refused_whatever_an_instance_in_memory_says(): void
    {
        $this->countedMetric();
        $ladder = $this->addLadder($this->draft(), ['bronze' => [10, $this->reaches(true, 'bronze')]]);
        $validated = $this->validatedLadder(['bronze' => [10, $this->reaches(true, 'bronze')]]);

        $ladder->planVersion->forceFill(['status' => PlanVersionStatus::Active]);
        $ladder->forceFill(['plan_version_id' => $validated->plan_version_id]);

        $this->assertRefused($ladder, $this->memberOf($ladder), 'component "career-ranks": its plan version is a draft');
    }

    public function test_a_component_of_another_driver_is_refused_not_unranked(): void
    {
        $this->countedMetric();
        $component = PlanComponent::query()->findOrFail($this->validatedRule($this->reaches(true, 'entry'))->plan_component_id);

        $this->assertRefused($component, $this->memberOf($component), 'component "entry" as a rank ladder: its driver is "test.criteria", not "rank.ladder".');
    }

    public function test_the_stored_component_decides_not_the_instance(): void
    {
        [$ladder, $member] = $this->ladderAndMember(['bronze' => [10, $this->reaches(true, 'bronze')]]);
        $criteria = PlanComponent::query()->findOrFail($this->validatedRule($this->reaches(true, 'entry'))->plan_component_id);

        // Unsaved changes: another driver, another version, another key.
        $ladder->forceFill(['driver' => 'test.criteria', 'plan_version_id' => $criteria->plan_version_id, 'key' => 'renamed']);
        $decision = $this->evaluate($ladder, $member);

        $this->assertSame(['career-ranks', 'bronze'], [$decision->componentKey, $decision->selectedRank?->key]);
        $this->assertNotSame($criteria->plan_version_id, $decision->planVersionId);

        // ...and claiming to be a ladder does not make a component one.
        $criteria->forceFill(['driver' => 'rank.ladder']);
        $this->assertRefused($criteria, $this->memberOf($criteria), 'its driver is "test.criteria"');
    }

    public function test_a_member_of_another_program_is_refused_before_any_rank_is_evaluated(): void
    {
        [$ladder] = $this->ladderAndMember(['bronze' => [10, $this->reaches(true, 'bronze')]]);
        $outsider = Member::factory()->create();

        $this->assertRefused($ladder, $outsider, 'the member belongs to program ['.$outsider->program_id.'], the rank ladder to program');
    }

    public function test_the_members_stored_program_decides_not_the_instance(): void
    {
        [$ladder, $member] = $this->ladderAndMember(['bronze' => [10, $this->reaches(true, 'bronze')]]);
        $outsider = Member::factory()->create();

        $member->program_id = $outsider->program_id;
        $this->assertSame('bronze', $this->evaluate($ladder, $member)->selectedRank?->key);

        $outsider->program_id = $member->getOriginal('program_id');
        $this->assertRefused($ladder, $outsider, 'the member belongs to program');
    }

    public function test_a_ladder_that_no_longer_exists_is_refused(): void
    {
        [$ladder, $member] = $this->ladderAndMember(['bronze' => [10, $this->reaches(true, 'bronze')]]);
        DB::table('mlm_plan_rules')->where('plan_component_id', $ladder->id)->delete();
        DB::table('mlm_plan_components')->where('id', $ladder->id)->delete();

        $this->assertRefused($ladder, $member, "the plan component [{$ladder->id}] does not exist");
    }

    /**
     * Stored ladders validation would refuse: only raw writes produce them.
     *
     * @return array<string, array{string, string}>
     */
    public static function corruptions(): array
    {
        return [
            'no rank left' => ['empty', 'it is not a valid rank ladder: a rank ladder needs at least one rank, but it has no rules.'],
            'two ranks at one position' => ['duplicate', 'it is not a valid rank ladder: ranks "silver" and "gold" share position 20'],
            'parameters' => ['parameters', 'it is not a valid rank ladder: a rank ladder takes no parameters, but it has "strategy".'],
            'unreadable parameters' => ['unreadable', 'it is not a valid rank ladder: The stored parameters of component "career-ranks" cannot be read: parameters are a JSON object'],
        ];
    }

    #[DataProvider('corruptions')]
    public function test_a_stored_ladder_validation_would_refuse_is_refused_not_answered(string $corruption, string $message): void
    {
        [$ladder, $member] = $this->ladderAndMember([
            'bronze' => [10, $this->reaches(true, 'bronze')],
            'silver' => [20, $this->reaches(true, 'silver')],
            'gold' => [30, $this->reaches(true, 'gold')],
        ]);
        $rules = DB::table('mlm_plan_rules')->where('plan_component_id', $ladder->id);
        $component = DB::table('mlm_plan_components')->where('id', $ladder->id);

        match ($corruption) {
            'empty' => $rules->delete(),
            'duplicate' => (clone $rules)->where('key', 'gold')->update(['position' => 20]),
            'parameters' => $component->update(['parameters' => '{"strategy": "lowest"}']),
            // Valid JSON, so MySQL and PostgreSQL store it, but not an object.
            'unreadable' => $component->update(['parameters' => '[1, 2]']),
        };

        $this->assertRefused($ladder, $member, "component \"career-ranks\": {$message}");
    }

    public function test_unreadable_parameters_keep_their_cause(): void
    {
        [$ladder, $member] = $this->ladderAndMember(['bronze' => [10, $this->reaches(true, 'bronze')]]);
        DB::table('mlm_plan_components')->where('id', $ladder->id)->update(['parameters' => '[1, 2]']);

        $exception = $this->refusal($ladder, $member);

        $this->assertInstanceOf(InvalidPlanDefinition::class, $exception->getPrevious());
    }

    public function test_a_rank_that_cannot_be_qualified_fails_the_whole_ladder(): void
    {
        $this->app->make(MetricRegistry::class)->register(new FailingMetric);
        [$ladder, $member] = $this->ladderAndMember([
            'bronze' => [10, $this->reaches(true, 'bronze')],
            'silver' => [20, RuleDefinition::all(MetricCondition::of('test.failing', [], '>=', '1'))],
            'gold' => [30, $this->reaches(true, 'gold')],
        ]);

        $exception = $this->refusal($ladder, $member);

        $this->assertStringContainsString('component "career-ranks": rank "silver" (position 20) could not be evaluated: Cannot evaluate', $exception->getMessage());
        $this->assertStringContainsString('rule "silver", root.children[0], metric "test.failing": the metric could not be resolved', $exception->getMessage());
        $this->assertInstanceOf(QualificationEvaluationException::class, $exception->getPrevious());
    }

    public function test_a_metric_missing_at_evaluation_fails_the_ladder_not_the_rank(): void
    {
        [$ladder, $member] = $this->ladderAndMember([
            'bronze' => [10, $this->reaches(true, 'bronze')],
            'silver' => [20, $this->reaches(true, 'silver')],
        ]);

        // The application stops registering the metric the version was
        // validated with.
        $this->app->forgetInstance(MetricRegistry::class);

        $exception = $this->refusal($ladder, $member);

        $this->assertStringContainsString('rank "bronze" (position 10) could not be evaluated', $exception->getMessage());
        $this->assertInstanceOf(UnknownMetric::class, $exception->getPrevious()?->getPrevious());
    }

    public function test_a_rank_stored_outside_the_rule_language_fails_the_ladder(): void
    {
        [$ladder, $member] = $this->ladderAndMember([
            'bronze' => [10, $this->reaches(true, 'bronze')],
            'silver' => [20, $this->reaches(true, 'silver')],
        ]);
        DB::table('mlm_plan_rules')->where('plan_component_id', $ladder->id)->where('key', 'silver')
            ->update(['definition' => '{"type": "group", "match": "all", "children": []}']);

        $exception = $this->refusal($ladder, $member);

        $this->assertStringContainsString('rank "silver" (position 20) could not be evaluated', $exception->getMessage());
        $this->assertInstanceOf(InvalidRuleDefinition::class, $exception->getPrevious()?->getPrevious());
    }

    public function test_an_application_metric_ranks_without_package_changes(): void
    {
        $this->app->make(MetricRegistry::class)->register(new ScoreMetric);
        $plan = Plan::factory()->create();
        $ladder = $this->validatedLadder([
            'bronze' => [10, RuleDefinition::all(MetricCondition::of('acme.score', ['window' => 30], '>=', '5'))],
            'silver' => [20, RuleDefinition::all(MetricCondition::of('acme.score', ['window' => 30], '>=', '10'))],
        ], $plan);

        $decision = $this->evaluate($ladder, Member::factory()->for($plan->program)->create());

        $this->assertSame('bronze', $decision->selectedRank?->key);
        $this->assertSame('7', $decision->toArray()['ranks'][1]['trace']['children'][0]['value']);
    }

    public function test_every_rank_receives_the_same_range(): void
    {
        [$ladder, $member] = $this->ladderAndMember([
            'bronze' => [10, $this->reaches(true, 'bronze')],
            'silver' => [20, $this->reaches(false, 'silver')],
            'gold' => [30, $this->reaches(true, 'gold')],
        ]);

        $decision = $this->evaluate($ladder, $member, '2026-06-01 00:00:00', '2026-07-01 00:00:00');

        $this->assertSame(['2026-06-01 00:00:00', '2026-07-01 00:00:00'], [$decision->toArray()['from'], $decision->toArray()['until']]);
        $this->assertSame(
            array_fill(0, 3, ['2026-06-01 00:00:00', '2026-07-01 00:00:00']),
            array_map(static fn (array $resolution): array => [$resolution['from'], $resolution['until']], $this->countedMetric()->resolutions),
        );
    }

    public function test_an_empty_or_inverted_range_is_refused(): void
    {
        $member = Member::factory()->create();

        foreach ([['2026-06-01', '2026-06-01'], ['2026-07-01', '2026-06-01']] as [$from, $until]) {
            try {
                new RankContext($member, CarbonImmutable::parse($from), CarbonImmutable::parse($until));
                $this->fail("[{$from}, {$until}) was accepted.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('must start before it ends', $exception->getMessage());
            }
        }
    }

    public function test_the_engine_ranks_through_qualification_alone(): void
    {
        $constructor = (new ReflectionClass(RankEngine::class))->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertSame([QualificationEngine::class], array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getType() instanceof ReflectionNamedType ? $parameter->getType()->getName() : '?',
            $constructor->getParameters(),
        ));
        $this->assertInstanceOf(RankEngine::class, $this->app->make(RankEngine::class));
    }

    public function test_evaluating_writes_nothing(): void
    {
        [$ladder, $member] = $this->ladderAndMember([
            'bronze' => [10, RuleDefinition::all($this->holds(true, 'bronze'), MetricCondition::of('member.volume', ['type' => 'sales'], '>=', '0'))],
            'silver' => [20, RuleDefinition::all(MetricCondition::of('sponsor.network.volume', ['type' => 'sales'], '>=', '0'))],
        ]);
        $before = $this->everyRow();

        $this->evaluate($ladder, $member);
        $this->evaluate($ladder, $member, '2026-01-01 00:00:00', '2027-01-01 00:00:00');

        $this->assertSame($before, $this->everyRow());
    }

    /**
     * A validated ladder with these ranks, and a member of its program.
     *
     * @param  array<string, array{int, RuleDefinition}>  $ranks
     * @return array{PlanComponent, Member}
     */
    private function ladderAndMember(array $ranks): array
    {
        $this->countedMetric();
        $ladder = $this->validatedLadder($ranks);

        return [$ladder, $this->memberOf($ladder)];
    }

    /**
     * A ladder — bronze reached, silver reached, gold not — in a version
     * brought to `$status` through the lifecycle. A superseded version is one
     * its own clone replaced.
     */
    private function ladderIn(PlanVersionStatus $status): PlanComponent
    {
        $this->countedMetric();
        $version = $this->draft();
        $ladder = $this->addLadder($version, [
            'bronze' => [10, $this->reaches(true, 'bronze')],
            'silver' => [20, $this->reaches(true, 'silver')],
            'gold' => [30, $this->reaches(false, 'gold')],
        ]);
        $lifecycle = $this->lifecycle();

        if ($status === PlanVersionStatus::Draft) {
            return $ladder;
        }

        $version = $lifecycle->markValidated($version);

        if ($status !== PlanVersionStatus::Validated) {
            $version = $lifecycle->publish($version);
        }

        if (in_array($status, [PlanVersionStatus::Active, PlanVersionStatus::Superseded, PlanVersionStatus::Archived], true)) {
            $version = $lifecycle->activate($version);
        }

        if (in_array($status, [PlanVersionStatus::Superseded, PlanVersionStatus::Archived], true)) {
            $lifecycle->activate($lifecycle->publish($lifecycle->markValidated($this->cloner()->cloneToNewDraft($version))));
        }

        if ($status === PlanVersionStatus::Archived) {
            $lifecycle->archive($version->refresh());
        }

        return $ladder;
    }

    private function memberOf(PlanComponent $component): Member
    {
        return Member::factory()->for($component->planVersion->plan->program)->create();
    }

    /**
     * A condition that holds or not, labelled so its resolution can be told
     * apart.
     */
    private function holds(bool $qualifies, string $label): MetricCondition
    {
        return $this->counted($qualifies ? '1' : '0', '>', '0', label: $label);
    }

    /**
     * A rank requirement of one such condition.
     */
    private function reaches(bool $qualifies, string $label): RuleDefinition
    {
        return RuleDefinition::all($this->holds($qualifies, $label));
    }

    private function counted(string $value, string $operator, string ...$operands): MetricCondition
    {
        $label = $operands['label'] ?? null;
        unset($operands['label']);

        return MetricCondition::of('test.counted', ['value' => $value, ...($label === null ? [] : ['label' => $label])], $operator, ...array_values($operands));
    }

    private function countedMetric(): CountedMetric
    {
        $registry = $this->app->make(MetricRegistry::class);

        if (! $registry->has('test.counted')) {
            $registry->register(new CountedMetric);
        }

        $metric = $registry->get('test.counted');
        assert($metric instanceof CountedMetric);

        return $metric;
    }

    private function evaluate(PlanComponent $ladder, Member $member, ?string $from = null, ?string $until = null): RankDecision
    {
        return $this->app->make(RankEngine::class)->evaluate($ladder, new RankContext(
            $member,
            $from === null ? null : CarbonImmutable::parse($from),
            $until === null ? null : CarbonImmutable::parse($until),
        ));
    }

    private function refusal(PlanComponent $ladder, Member $member): RankEvaluationException
    {
        try {
            $this->evaluate($ladder, $member);
        } catch (RankEvaluationException $exception) {
            return $exception;
        }

        $this->fail('The ladder was evaluated.');
    }

    /**
     * Refused with `$message`, before any rank was qualified.
     */
    private function assertRefused(PlanComponent $ladder, Member $member, string $message): void
    {
        $resolved = $this->countedMetric()->resolutions;

        $this->assertStringContainsString($message, $this->refusal($ladder, $member)->getMessage());
        $this->assertSame($resolved, $this->countedMetric()->resolutions, 'A rank was qualified before the request was refused.');
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function everyRow(): array
    {
        $rows = [];

        foreach (['mlm_programs', 'mlm_members', 'mlm_plans', 'mlm_plan_versions', 'mlm_plan_components', 'mlm_plan_rules', 'mlm_sponsor_edges', 'mlm_placement_edges', 'mlm_genealogy_paths', 'mlm_volume_entries'] as $table) {
            $rows[$table] = DB::table($table)->get()->map(static fn (object $row): array => (array) $row)->sortBy(static fn (array $row): string => json_encode($row))->values()->all();
        }

        return $rows;
    }
}
