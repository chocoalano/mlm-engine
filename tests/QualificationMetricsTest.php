<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\PlanRule;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Planning\Rules\MetricCondition;
use PandaBear\Mlm\Planning\Rules\RuleDefinition;
use PandaBear\Mlm\Qualification\QualificationContext;
use PandaBear\Mlm\Qualification\QualificationDecision;
use PandaBear\Mlm\Qualification\QualificationEngine;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;
use PandaBear\Mlm\Tests\Concerns\BuildsPlanDefinitions;
use PandaBear\Mlm\Tests\Concerns\RecordsVolume;

/**
 * Qualification over the built-in metrics and real records: it composes what
 * the metrics resolve — their history and reversal rules included — and adds
 * nothing of its own.
 */
final class QualificationMetricsTest extends DatabaseTestCase
{
    use BuildsGenealogies;
    use BuildsPlanDefinitions;
    use RecordsVolume;

    public function test_a_rule_over_all_three_built_in_metrics(): void
    {
        $program = Program::factory()->create();
        $members = $this->members($program, 'Alice', 'Bob', 'Charlie');
        $this->link('sponsor', $members, '2026-01-01 00:00:00', 'Alice', 'Bob');
        $this->link('placement', $members, '2026-01-01 00:00:00', 'Alice', 'Charlie');

        $this->record($members['Alice'], '120', 'alice', at: $this->at('2026-02-01 00:00:00'));
        $this->record($members['Bob'], '250', 'bob', at: $this->at('2026-02-01 00:00:00'));
        $this->record($members['Charlie'], '299.5', 'charlie', at: $this->at('2026-02-01 00:00:00'));

        $rule = $this->ruleIn($program, RuleDefinition::all(
            MetricCondition::of('member.volume', ['type' => 'sales'], '>=', '100'),
            MetricCondition::of('sponsor.network.volume', ['type' => 'sales'], '>=', '200'),
            MetricCondition::of('placement.network.volume', ['type' => 'sales', 'max_depth' => 1], '>=', '300'),
        ));

        $decision = $this->evaluate($rule, $members['Alice']);

        $this->assertFalse($decision->qualified);
        $this->assertSame([
            ['member.volume', '120', true],
            ['sponsor.network.volume', '250', true],
            ['placement.network.volume', '299.5', false],
        ], $this->conditions($decision));

        $this->record($members['Charlie'], '0.5', 'charlie-more', at: $this->at('2026-02-02 00:00:00'));

        $this->assertTrue($this->evaluate($rule, $members['Alice'])->qualified);
    }

    public function test_network_qualification_follows_the_genealogy_at_the_time_of_the_activity(): void
    {
        $program = Program::factory()->create();
        $members = $this->members($program, 'Alice', 'Charlie');

        // January: Charlie sells while still a root. February: Alice
        // sponsors Charlie. March: Charlie sells again.
        $this->record($members['Charlie'], '100', 'jan', at: $this->at('2026-01-10 00:00:00'));
        $this->link('sponsor', $members, '2026-02-01 00:00:00', 'Alice', 'Charlie');
        $this->record($members['Charlie'], '50', 'mar', at: $this->at('2026-03-10 00:00:00'));

        $rule = $this->ruleIn($program, RuleDefinition::all(MetricCondition::of('sponsor.network.volume', ['type' => 'sales'], '>=', '100')));

        $decision = $this->evaluate($rule, $members['Alice']);

        $this->assertFalse($decision->qualified);
        $this->assertSame([['sponsor.network.volume', '50', false]], $this->conditions($decision));
    }

    public function test_network_qualification_follows_reversals_as_the_metric_does(): void
    {
        $program = Program::factory()->create();
        $members = $this->members($program, 'Alice', 'Bob', 'Charlie');
        $this->link('placement', $members, '2026-01-01 00:00:00', 'Bob', 'Charlie');

        $sale = $this->record($members['Charlie'], '100', 'jan', at: $this->at('2026-01-15 00:00:00'));
        // Alice joins above Bob in March; the January sale is refunded in April.
        $this->link('placement', $members, '2026-03-01 00:00:00', 'Alice', 'Bob');
        $this->reverse($sale, 'jan-refund', at: $this->at('2026-04-10 00:00:00'));

        $rule = $this->ruleIn($program, RuleDefinition::all(MetricCondition::of('placement.network.volume', ['type' => 'sales'], '>=', '100')));

        $january = ['2026-01-01 00:00:00', '2026-02-01 00:00:00'];
        $april = ['2026-04-01 00:00:00', '2026-05-01 00:00:00'];

        $this->assertTrue($this->evaluate($rule, $members['Bob'], ...$january)->qualified);
        $this->assertSame([['placement.network.volume', '-100', false]], $this->conditions($this->evaluate($rule, $members['Bob'], ...$april)));
        $this->assertSame([['placement.network.volume', '0', false]], $this->conditions($this->evaluate($rule, $members['Bob'])));

        // Alice received neither the sale nor its refund.
        $this->assertSame([['placement.network.volume', '0', false]], $this->conditions($this->evaluate($rule, $members['Alice'], ...$january)));
        $this->assertSame([['placement.network.volume', '0', false]], $this->conditions($this->evaluate($rule, $members['Alice'], ...$april)));
    }

    public function test_the_range_includes_its_start_and_excludes_its_end(): void
    {
        $program = Program::factory()->create();
        $member = Member::factory()->for($program)->create();
        $this->record($member, '1', 'before', at: $this->at('2026-05-31 23:59:59'));
        $this->record($member, '10', 'start', at: $this->at('2026-06-01 00:00:00'));
        $this->record($member, '100', 'end', at: $this->at('2026-07-01 00:00:00'));

        $rule = $this->ruleIn($program, RuleDefinition::all(MetricCondition::of('member.volume', ['type' => 'sales'], '>=', '0')));
        $value = fn (?string $from, ?string $until): string => $this->conditions($this->evaluate($rule, $member, $from, $until))[0][1];

        $this->assertSame('10', $value('2026-06-01 00:00:00', '2026-07-01 00:00:00'));
        $this->assertSame('110', $value('2026-06-01 00:00:00', null));
        $this->assertSame('11', $value(null, '2026-07-01 00:00:00'));
        $this->assertSame('111', $value(null, null));
    }

    private function ruleIn(Program $program, RuleDefinition $definition): PlanRule
    {
        return $this->validatedRule($definition, $program->plans()->create(['code' => 'MAIN', 'name' => 'Main']));
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
     * @return list<array{string, string, bool}> metric, value, outcome of each top-level condition
     */
    private function conditions(QualificationDecision $decision): array
    {
        return array_map(
            static fn (array $node): array => [$node['metric'], $node['value'], $node['passed']],
            $decision->toArray()['trace']['children'],
        );
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
