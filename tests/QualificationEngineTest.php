<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PandaBear\Mlm\Exceptions\InvalidRuleDefinition;
use PandaBear\Mlm\Exceptions\QualificationEvaluationException;
use PandaBear\Mlm\Exceptions\UnknownMetric;
use PandaBear\Mlm\Metrics\MetricRegistry;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanComponent;
use PandaBear\Mlm\Models\PlanRule;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Planning\PlanDefinitionValidator;
use PandaBear\Mlm\Planning\PlanVersionStatus;
use PandaBear\Mlm\Planning\Rules\MetricCondition;
use PandaBear\Mlm\Planning\Rules\RuleDefinition;
use PandaBear\Mlm\Planning\Rules\RuleGroup;
use PandaBear\Mlm\Qualification\QualificationConditionTrace;
use PandaBear\Mlm\Qualification\QualificationContext;
use PandaBear\Mlm\Qualification\QualificationDecision;
use PandaBear\Mlm\Qualification\QualificationEngine;
use PandaBear\Mlm\Tests\Concerns\BuildsPlanDefinitions;
use PandaBear\Mlm\Tests\Fixtures\CountedMetric;
use PandaBear\Mlm\Tests\Fixtures\FailingMetric;
use PandaBear\Mlm\Tests\Fixtures\ScoreMetric;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * One stored rule, chosen by the caller, evaluated for one member: exactly,
 * completely, from stored data, and never confusing "cannot evaluate" with
 * "does not qualify".
 */
final class QualificationEngineTest extends DatabaseTestCase
{
    use BuildsPlanDefinitions;

    /**
     * @return array<string, array{string, string, list<string>, bool}>
     */
    public static function comparisons(): array
    {
        return [
            '!= different' => ['5', '!=', ['6'], true],
            '!= same' => ['5', '!=', ['5.0'], false],
            '> above by a millionth' => ['5.000001', '>', ['5'], true],
            '> equal' => ['5', '>', ['5'], false],
            '> not lexical: 9 against 10' => ['9', '>', ['10'], false],
            '> not lexical: 100 against 99' => ['100', '>', ['99'], true],
            '>= at the boundary' => ['5', '>=', ['5'], true],
            '>= below by a millionth' => ['4.999999', '>=', ['5'], false],
            '< negative below zero' => ['-1', '<', ['0'], true],
            '< equal' => ['0', '<', ['0'], false],
            '<= at the boundary' => ['0', '<=', ['0'], true],
            '<= above by a millionth' => ['0.000001', '<=', ['0'], false],
            'in: one of them' => ['2.5', 'in', ['1', '2.5', '3'], true],
            'in: none of them' => ['2.4', 'in', ['1', '2.5', '3'], false],
            'not_in: one of them' => ['2.5', 'not_in', ['1', '2.5', '3'], false],
            'not_in: none of them' => ['2.4', 'not_in', ['1', '2.5', '3'], true],
            'between: below' => ['9.999999', 'between', ['10', '20'], false],
            'between: the lower bound' => ['10', 'between', ['10', '20'], true],
            'between: inside' => ['15.5', 'between', ['10', '20'], true],
            'between: the upper bound' => ['20', 'between', ['10', '20'], true],
            'between: above' => ['20.000001', 'between', ['10', '20'], false],
            'between: negative range' => ['-5', 'between', ['-10', '-1'], true],
            'beyond 64 bits' => ['99999999999999999999.5', '>', ['99999999999999999999.4'], true],
            'beyond 64 bits, negative' => ['-99999999999999999999', '<', ['-9999999999999999999'], true],
            'beyond 64 bits, equal' => ['123456789012345678901234.000001', 'in', ['123456789012345678901234.000001'], true],
        ];
    }

    /**
     * @param  list<string>  $operands
     */
    #[DataProvider('comparisons')]
    public function test_each_operator_compares_exactly(string $value, string $operator, array $operands, bool $holds): void
    {
        [$rule, $member] = $this->ruleAndMember(RuleDefinition::all($this->counted($value, $operator, ...$operands)));

        $decision = $this->evaluate($rule, $member);
        $condition = $decision->trace->children[0];

        $this->assertSame($holds, $decision->qualified);
        $this->assertInstanceOf(QualificationConditionTrace::class, $condition);
        $this->assertSame($holds, $condition->passed);
        $this->assertSame(MetricCondition::of('test.counted', [], $operator, ...$operands)->operands, $condition->operands);
        $this->assertSame($operator, $condition->toArray()['operator']);
    }

    /**
     * @return array<string, array{RuleDefinition, bool, list<bool>}>
     */
    public static function groupCases(): array
    {
        $yes = static fn (string $label): MetricCondition => MetricCondition::of('test.counted', ['label' => $label, 'value' => '1'], '>', '0');
        $no = static fn (string $label): MetricCondition => MetricCondition::of('test.counted', ['label' => $label, 'value' => '0'], '>', '0');

        return [
            'all, every child true' => [RuleDefinition::all($yes('a'), $yes('b')), true, [true, true]],
            'all, one child false' => [RuleDefinition::all($yes('a'), $no('b'), $yes('c')), false, [true, false, true]],
            'any, one child true' => [RuleDefinition::any($no('a'), $yes('b')), true, [false, true]],
            'any, every child false' => [RuleDefinition::any($no('a'), $no('b')), false, [false, false]],
            'nested all in any' => [RuleDefinition::any($no('a'), RuleGroup::all($yes('b'), $yes('c'))), true, [false, true]],
            'nested any in all' => [RuleDefinition::all($yes('a'), RuleGroup::any($no('b'), $no('c'))), false, [true, false]],
            'mixed nesting' => [RuleDefinition::all(RuleGroup::any($no('a'), RuleGroup::all($yes('b'), $yes('c'))), $yes('d')), true, [true, true]],
        ];
    }

    /**
     * @param  list<bool>  $children
     */
    #[DataProvider('groupCases')]
    public function test_groups_combine_their_children(RuleDefinition $definition, bool $qualified, array $children): void
    {
        [$rule, $member] = $this->ruleAndMember($definition);

        $decision = $this->evaluate($rule, $member);

        $this->assertSame($qualified, $decision->qualified);
        $this->assertSame($qualified, $decision->trace->passed);
        $this->assertSame($children, array_map(static fn ($child): bool => $child->passed(), $decision->trace->children));
    }

    public function test_every_condition_is_resolved_even_after_the_group_is_decided(): void
    {
        $metric = $this->countedMetric();

        // An all group decided by its first child, then an any group decided
        // by its first child: every later condition is still resolved, and
        // the same metric and parameters twice are resolved twice.
        [$rule, $member] = $this->ruleAndMember(RuleDefinition::all(
            RuleGroup::all(
                $this->counted('0', '>', '1', label: 'all-first-false'),
                $this->counted('5', '>', '1', label: 'all-second'),
                $this->counted('5', '>', '1', label: 'all-second'),
            ),
            RuleGroup::any(
                $this->counted('5', '>', '1', label: 'any-first-true'),
                $this->counted('0', '>', '1', label: 'any-second'),
            ),
        ));

        $decision = $this->evaluate($rule, $member);

        $this->assertFalse($decision->qualified);
        $this->assertSame(
            ['all-first-false', 'all-second', 'all-second', 'any-first-true', 'any-second'],
            array_column($metric->resolutions, 'label'),
        );
        $this->assertSame(
            ['root.children[0].children[0]', 'root.children[0].children[1]', 'root.children[0].children[2]', 'root.children[1].children[0]', 'root.children[1].children[1]'],
            $this->conditionPaths($decision->toArray()['trace']),
        );
    }

    public function test_the_decision_names_its_rule_and_explains_every_node(): void
    {
        $this->countedMetric();
        $program = Program::factory()->create(['code' => 'MAIN']);
        $plan = Plan::factory()->for($program)->create(['code' => 'STANDARD']);
        $member = Member::factory()->for($program)->create(['member_code' => 'M-1']);
        $rule = $this->validatedRule(RuleDefinition::all(
            $this->counted('150', '>=', '100', label: 'volume'),
            RuleGroup::any(
                $this->counted('3', 'between', '1', '2', label: 'depth'),
                $this->counted('7', 'in', '7', '8', label: 'score'),
            ),
        ), $plan, 'gold');

        $decision = $this->evaluate($rule, $member, '2026-06-01 00:00:00', '2026-07-01 00:00:00');
        $component = PlanComponent::query()->findOrFail($rule->plan_component_id);

        $this->assertSame([
            'qualified' => true,
            'program' => ['id' => $program->id, 'code' => 'MAIN'],
            'plan' => ['id' => $plan->id, 'code' => 'STANDARD'],
            'plan_version' => ['id' => $component->plan_version_id, 'version' => 1],
            'component' => 'entry',
            'rule' => 'gold',
            'member' => ['id' => $member->id, 'member_code' => 'M-1'],
            'from' => '2026-06-01 00:00:00',
            'until' => '2026-07-01 00:00:00',
            'trace' => [
                'type' => 'group', 'path' => 'root', 'match' => 'all', 'passed' => true, 'children' => [
                    ['type' => 'condition', 'path' => 'root.children[0]', 'metric' => 'test.counted', 'parameters' => ['label' => 'volume', 'value' => '150'], 'value' => '150', 'operator' => '>=', 'operands' => ['100'], 'passed' => true],
                    ['type' => 'group', 'path' => 'root.children[1]', 'match' => 'any', 'passed' => true, 'children' => [
                        ['type' => 'condition', 'path' => 'root.children[1].children[0]', 'metric' => 'test.counted', 'parameters' => ['label' => 'depth', 'value' => '3'], 'value' => '3', 'operator' => 'between', 'operands' => ['1', '2'], 'passed' => false],
                        ['type' => 'condition', 'path' => 'root.children[1].children[1]', 'metric' => 'test.counted', 'parameters' => ['label' => 'score', 'value' => '7'], 'value' => '7', 'operator' => 'in', 'operands' => ['7', '8'], 'passed' => true],
                    ]],
                ],
            ],
        ], $decision->toArray());

        $this->assertSame('gold', $decision->ruleKey);
        $this->assertSame(1, $decision->planVersion);
    }

    public function test_the_same_stored_state_gives_the_same_decision_whatever_the_clock(): void
    {
        [$rule, $member] = $this->ruleAndMember(RuleDefinition::any($this->counted('1', '>', '0'), $this->counted('2', '<', '1')));

        $this->travelTo(CarbonImmutable::parse('2026-01-01 00:00:00'));
        $first = $this->evaluate($rule, $member, '2026-03-01 00:00:00')->toArray();
        $this->travelTo(CarbonImmutable::parse('2031-12-31 23:59:59'));
        $second = $this->evaluate($rule, $member, '2026-03-01 00:00:00')->toArray();

        $this->assertSame($first, $second);
        $this->assertArrayNotHasKey('evaluated_at', $first);
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
    public function test_a_rule_of_any_validated_version_can_be_evaluated(PlanVersionStatus $status): void
    {
        $version = $this->versionIn($status);
        $rule = $version->components()->sole()->rules()->sole();
        $member = Member::factory()->for($version->plan->program)->create();

        $decision = $this->evaluate($rule, $member);

        $this->assertSame($version->id, $decision->planVersionId);
        $this->assertFalse($decision->qualified);
        $this->assertCount(3, $this->conditionPaths($decision->toArray()['trace']));
    }

    public function test_a_draft_rule_is_refused(): void
    {
        $version = $this->validDraft();
        $rule = $version->components()->sole()->rules()->sole();

        $this->expectException(QualificationEvaluationException::class);
        $this->expectExceptionMessage('its plan version is a draft');

        $this->evaluate($rule, Member::factory()->for($version->plan->program)->create());
    }

    public function test_a_draft_stays_refused_whatever_an_instance_in_memory_says(): void
    {
        $version = $this->validDraft();
        $rule = $version->components()->sole()->rules()->sole();

        $rule->component->planVersion->forceFill(['status' => PlanVersionStatus::Active]);
        $rule->component->forceFill(['plan_version_id' => 'another-version']);

        $this->expectException(QualificationEvaluationException::class);
        $this->expectExceptionMessage('its plan version is a draft');

        $this->evaluate($rule, Member::factory()->for($version->plan->program)->create());
    }

    public function test_the_stored_rule_decides_its_ownership_not_the_instance(): void
    {
        [$rule, $member] = $this->ruleAndMember(RuleDefinition::all($this->counted('1', '>', '0')));
        $storedVersion = PlanComponent::query()->findOrFail($rule->plan_component_id)->plan_version_id;

        // An unsaved change pointing the rule at a draft's component.
        $draftComponent = $this->editor()->addComponent($this->draft(), 'other', 'test.criteria', 'Other');
        $rule->forceFill(['plan_component_id' => $draftComponent->id, 'key' => 'renamed']);

        $decision = $this->evaluate($rule, $member);

        $this->assertSame($storedVersion, $decision->planVersionId);
        $this->assertSame('rule', $decision->ruleKey);
        $this->assertTrue($decision->qualified);
    }

    public function test_a_member_of_another_program_is_refused_not_failed(): void
    {
        [$rule] = $this->ruleAndMember(RuleDefinition::all($this->counted('1', '>', '0')));
        $outsider = Member::factory()->create();

        $this->expectException(QualificationEvaluationException::class);
        $this->expectExceptionMessage('the member belongs to program ['.$outsider->program_id.']');

        $this->evaluate($rule, $outsider);
    }

    public function test_the_members_stored_program_decides_not_the_instance(): void
    {
        [$rule, $member] = $this->ruleAndMember(RuleDefinition::all($this->counted('1', '>', '0')));
        $outsider = Member::factory()->create();

        // Claiming the other program in memory does not move the member...
        $member->program_id = $outsider->program_id;
        $this->assertTrue($this->evaluate($rule, $member)->qualified);

        // ...and claiming the rule's program does not bring an outsider in.
        $outsider->program_id = $member->getOriginal('program_id');
        $this->expectException(QualificationEvaluationException::class);
        $this->expectExceptionMessage('the member belongs to program');

        $this->evaluate($rule, $outsider);
    }

    public function test_a_rule_that_no_longer_exists_is_refused(): void
    {
        [$rule, $member] = $this->ruleAndMember(RuleDefinition::all($this->counted('1', '>', '0')));
        DB::table('mlm_plan_rules')->where('id', $rule->id)->delete();

        $this->expectException(QualificationEvaluationException::class);
        $this->expectExceptionMessage("the plan rule [{$rule->id}] does not exist");

        $this->evaluate($rule, $member);
    }

    public function test_a_stored_rule_outside_the_language_is_refused_never_read_as_false(): void
    {
        [$rule, $member] = $this->ruleAndMember(RuleDefinition::all($this->counted('1', '>', '0')));
        DB::table('mlm_plan_rules')->where('id', $rule->id)->update(['definition' => '{"type": "group", "match": "all", "children": []}']);

        try {
            $this->evaluate($rule, $member);
            $this->fail('A corrupt rule was evaluated.');
        } catch (QualificationEvaluationException $exception) {
            $this->assertStringContainsString('component "entry", rule "rule": its stored definition cannot be read', $exception->getMessage());
            $this->assertInstanceOf(InvalidRuleDefinition::class, $exception->getPrevious());
        }
    }

    public function test_a_metric_missing_at_evaluation_is_an_error_not_a_failed_qualification(): void
    {
        $this->app->make(MetricRegistry::class)->register(new ScoreMetric);
        [$rule, $member] = $this->ruleAndMember(RuleDefinition::all(MetricCondition::of('acme.score', ['window' => 30], '>=', '1')));

        // Validated while the application registered the metric; it no longer does.
        $this->app->forgetInstance(MetricRegistry::class);
        $this->app->forgetInstance(PlanDefinitionValidator::class);

        try {
            $this->evaluate($rule, $member);
            $this->fail('A missing metric was evaluated.');
        } catch (QualificationEvaluationException $exception) {
            $this->assertStringContainsString('rule "rule", root.children[0], metric "acme.score": the metric could not be resolved', $exception->getMessage());
            $this->assertInstanceOf(UnknownMetric::class, $exception->getPrevious());
        }
    }

    public function test_a_metric_that_fails_to_resolve_is_an_error_not_a_failed_qualification(): void
    {
        $this->app->make(MetricRegistry::class)->register(new FailingMetric);
        [$rule, $member] = $this->ruleAndMember(RuleDefinition::any(
            $this->counted('1', '>', '0'),
            MetricCondition::of('test.failing', [], '>', '0'),
        ));

        try {
            $this->evaluate($rule, $member);
            $this->fail('A metric failure became a decision.');
        } catch (QualificationEvaluationException $exception) {
            $this->assertStringContainsString('root.children[1], metric "test.failing"', $exception->getMessage());
            $this->assertInstanceOf(RuntimeException::class, $exception->getPrevious());
            $this->assertSame('The metric\'s data source is unavailable.', $exception->getPrevious()->getMessage());
        }
    }

    public function test_an_application_metric_is_evaluated_without_package_changes(): void
    {
        $this->app->make(MetricRegistry::class)->register(new ScoreMetric);
        [$rule, $member] = $this->ruleAndMember(RuleDefinition::all(MetricCondition::of('acme.score', ['window' => 30], 'between', '5', '10')));

        $decision = $this->evaluate($rule, $member);

        $this->assertTrue($decision->qualified);
        $this->assertSame('7', $decision->toArray()['trace']['children'][0]['value']);
    }

    public function test_every_condition_receives_the_same_range(): void
    {
        $metric = $this->countedMetric();
        [$rule, $member] = $this->ruleAndMember(RuleDefinition::all(
            $this->counted('1', '>', '0', label: 'a'),
            RuleGroup::any($this->counted('1', '>', '0', label: 'b')),
        ));

        $this->evaluate($rule, $member, '2026-06-01 00:00:00');
        $this->evaluate($rule, $member, null, '2026-07-01 00:00:00');

        $this->assertSame([
            ['2026-06-01 00:00:00', null], ['2026-06-01 00:00:00', null],
            [null, '2026-07-01 00:00:00'], [null, '2026-07-01 00:00:00'],
        ], array_map(static fn (array $resolution): array => [$resolution['from'], $resolution['until']], $metric->resolutions));
    }

    public function test_an_empty_or_inverted_range_is_refused(): void
    {
        $member = Member::factory()->create();

        foreach ([['2026-06-01', '2026-06-01'], ['2026-07-01', '2026-06-01']] as [$from, $until]) {
            try {
                new QualificationContext($member, CarbonImmutable::parse($from), CarbonImmutable::parse($until));
                $this->fail("[{$from}, {$until}) was accepted.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('must start before it ends', $exception->getMessage());
            }
        }
    }

    public function test_evaluating_writes_nothing(): void
    {
        [$rule, $member] = $this->ruleAndMember(RuleDefinition::all(
            $this->counted('1', '>', '0'),
            MetricCondition::of('member.volume', ['type' => 'sales'], '>=', '0'),
            MetricCondition::of('sponsor.network.volume', ['type' => 'sales'], '>=', '0'),
        ));
        $before = $this->everyRow();

        $this->evaluate($rule, $member);
        $this->evaluate($rule, $member, '2026-01-01 00:00:00', '2027-01-01 00:00:00');

        $this->assertSame($before, $this->everyRow());
    }

    /**
     * A validated rule with this definition, and a member of its program.
     *
     * @return array{PlanRule, Member}
     */
    private function ruleAndMember(RuleDefinition $definition): array
    {
        $this->countedMetric();
        $plan = Plan::factory()->create();

        return [$this->validatedRule($definition, $plan), Member::factory()->for($plan->program)->create()];
    }

    private function counted(string $value, string $operator, string ...$operands): MetricCondition
    {
        $label = null;

        if (array_key_exists('label', $operands)) {
            $label = $operands['label'];
            unset($operands['label']);
        }

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

    private function evaluate(PlanRule $rule, Member $member, ?string $from = null, ?string $until = null): QualificationDecision
    {
        return $this->app->make(QualificationEngine::class)->evaluate($rule, new QualificationContext(
            $member,
            $from === null ? null : CarbonImmutable::parse($from),
            $until === null ? null : CarbonImmutable::parse($until),
        ));
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private function conditionPaths(array $node): array
    {
        if ($node['type'] === 'condition') {
            return [$node['path']];
        }

        return array_merge(...array_map(fn (array $child): array => $this->conditionPaths($child), $node['children']));
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
