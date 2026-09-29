<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Binary\BinarySide;
use PandaBear\Mlm\Exceptions\InvalidMetricParameters;
use PandaBear\Mlm\Metrics\MatrixNetworkVolumeMetric;
use PandaBear\Mlm\Metrics\MetricContext;
use PandaBear\Mlm\Metrics\MetricEngine;
use PandaBear\Mlm\Metrics\MetricRegistry;
use PandaBear\Mlm\Metrics\PlanConfigurableMetric;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Planning\Rules\MetricCondition;
use PandaBear\Mlm\Planning\Rules\RuleDefinition;
use PandaBear\Mlm\Qualification\QualificationContext;
use PandaBear\Mlm\Qualification\QualificationEngine;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;
use PandaBear\Mlm\Tests\Concerns\BuildsPlanDefinitions;
use PandaBear\Mlm\Tests\Concerns\RecordsVolume;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `matrix.network.volume` (ADR-027): the volume of the members below a
 * member in the matrix, each entry counted only if its member was there when
 * the activity happened. The member's own volume, generic-only members and
 * edges adopted later never count, and a reversal lands where the activity
 * it corrects landed.
 */
final class MatrixNetworkVolumeMetricTest extends DatabaseTestCase
{
    use BuildsGenealogies;
    use BuildsPlanDefinitions;
    use RecordsVolume;

    private Program $program;

    /**
     * @var array<string, Member>
     */
    private array $members;

    protected function setUp(): void
    {
        parent::setUp();

        $this->program = Program::factory()->create();
        $this->members = $this->members($this->program, 'N', 'A', 'B', 'C', 'D', 'X');
        $this->matrixNetworks()->configure($this->program, 3);
    }

    public function test_it_is_a_plan_configurable_built_in(): void
    {
        $metric = $this->app->make(MetricRegistry::class)->get('matrix.network.volume');

        $this->assertInstanceOf(MatrixNetworkVolumeMetric::class, $metric);
        $this->assertInstanceOf(PlanConfigurableMetric::class, $metric);
    }

    public function test_an_empty_matrix_is_zero_and_the_members_own_volume_never_counts(): void
    {
        $this->record($this->members['N'], '70', 'own', at: $this->at('2026-02-01'));

        $this->assertSame('0', $this->network('N'));
        $this->assertSame('0', $this->network('X'));
    }

    public function test_every_slot_and_every_depth_below_counts(): void
    {
        // N > A #1 > C #3 > D #1; N > B #2.
        $this->link('2026-01-01', 'N', 'A', 1);
        $this->link('2026-01-01', 'N', 'B', 2);
        $this->link('2026-01-01', 'A', 'C', 3);
        $this->link('2026-01-01', 'C', 'D', 1);

        foreach (['N' => '1000', 'A' => '1', 'B' => '10', 'C' => '100', 'D' => '0.5'] as $code => $quantity) {
            $this->record($this->members[$code], $quantity, strtolower($code), at: $this->at('2026-02-01'));
        }

        $this->assertSame('111.5', $this->network('N'));
        $this->assertSame('11', $this->network('N', maxDepth: 1));
        $this->assertSame('111', $this->network('N', maxDepth: 2));
        $this->assertSame('100.5', $this->network('A'));
        $this->assertSame('0', $this->network('D'));
    }

    public function test_generic_only_members_never_count(): void
    {
        $this->link('2026-01-01', 'N', 'A', 1);
        $this->travelTo($this->at('2026-01-01'));
        $this->placement()->place($this->members['X'], $this->members['A']);
        $this->placement()->place($this->members['B'], $this->members['N']);
        $this->record($this->members['X'], '40', 'x', at: $this->at('2026-02-01'));
        $this->record($this->members['B'], '50', 'b', at: $this->at('2026-02-01'));
        $this->record($this->members['A'], '5', 'a', at: $this->at('2026-02-01'));

        $this->assertSame('5', $this->network('N'));
        $this->assertSame('95', $this->engine()->resolve('placement.network.volume', new MetricContext($this->members['N'], ['type' => 'sales']))->value());
    }

    public function test_only_the_requested_type_and_the_period_from_its_start_to_before_its_end_count(): void
    {
        $this->link('2026-01-01', 'N', 'A', 1);
        $this->record($this->members['A'], '1', 'start', at: $this->at('2026-02-01 00:00:00'));
        $this->record($this->members['A'], '10', 'end', at: $this->at('2026-03-01 00:00:00'));
        $this->record($this->members['A'], '100', 'points', 'points', at: $this->at('2026-02-10'));

        $this->assertSame('1', $this->network('N', from: '2026-02-01', until: '2026-03-01'));
        $this->assertSame('100', $this->network('N', type: 'points'));
    }

    public function test_an_edge_adopted_later_never_captures_earlier_activity(): void
    {
        // January: A sells while placed only generically. March: adopted.
        $this->travelTo($this->at('2026-01-01'));
        $edge = $this->placement()->place($this->members['A'], $this->members['N']);
        $this->record($this->members['A'], '100', 'jan', at: $this->at('2026-01-15'));
        $this->travelTo($this->at('2026-03-01'));
        $this->matrix()->adopt($edge, 1);
        $this->record($this->members['A'], '7', 'apr', at: $this->at('2026-04-01'));

        $this->assertSame('7', $this->network('N'));
        $this->assertSame('0', $this->network('N', until: '2026-03-01'));
    }

    public function test_a_reversal_of_activity_from_before_the_adoption_counts_for_neither(): void
    {
        $this->travelTo($this->at('2026-01-01'));
        $edge = $this->placement()->place($this->members['A'], $this->members['N']);
        $sale = $this->record($this->members['A'], '100', 'jan', at: $this->at('2026-01-15'));
        $this->travelTo($this->at('2026-03-01'));
        $this->matrix()->adopt($edge, 1);
        $this->reverse($sale, 'jan-refund', at: $this->at('2026-04-10'));

        $this->assertSame('0', $this->network('N'));
        $this->assertSame('0', $this->network('N', from: '2026-04-01', until: '2026-05-01'));
    }

    public function test_a_later_reversal_is_clawed_back_in_its_own_period_and_one_in_the_same_period_nets_out(): void
    {
        $this->link('2026-01-01', 'N', 'A', 1);
        $sale = $this->record($this->members['A'], '100', 'feb', at: $this->at('2026-02-10'));
        $this->reverse($sale, 'feb-refund', at: $this->at('2026-04-10'));
        $same = $this->record($this->members['A'], '30', 'may', at: $this->at('2026-05-10'));
        $this->record($this->members['A'], '8', 'may-kept', at: $this->at('2026-05-11'));
        $this->reverse($same, 'may-refund', at: $this->at('2026-05-20'));

        $this->assertSame('100', $this->network('N', from: '2026-02-01', until: '2026-03-01'));
        $this->assertSame('-100', $this->network('N', from: '2026-04-01', until: '2026-05-01'));
        $this->assertSame('8', $this->network('N', from: '2026-05-01', until: '2026-06-01'));
        $this->assertSame('8', $this->network('N'));
    }

    public function test_values_are_exact(): void
    {
        $this->link('2026-01-01', 'N', 'A', 1);
        $this->link('2026-01-01', 'A', 'B', 2);
        $this->record($this->members['A'], '0.1', 'a1', at: $this->at('2026-02-01'));
        $this->record($this->members['A'], '0.1', 'a2', at: $this->at('2026-02-01'));
        $this->record($this->members['B'], '0.000001', 'b', at: $this->at('2026-02-01'));

        $this->assertSame('0.200001', $this->network('N'));
    }

    public function test_a_network_beyond_a_64_bit_count_of_millionths_is_exact(): void
    {
        if ($this->app->make('db')->connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite overflows SUM past 64 bits (ADR-010); MySQL and PostgreSQL widen it.');
        }

        // Ten below N, each selling the largest quantity.
        $parent = 'N';

        foreach (range(1, 10) as $i) {
            $this->members["M{$i}"] = Member::factory()->for($this->program)->create(['member_code' => "M{$i}"]);
            $this->link('2026-01-01', $parent, "M{$i}", 1);
            $this->record($this->members["M{$i}"], '999999999999.999999', "large-{$i}", at: $this->at('2026-02-01'));
            $parent = "M{$i}";
        }

        $this->assertSame('9999999999999.99999', $this->network('N'));
    }

    public function test_the_binary_and_sponsor_trees_play_no_part(): void
    {
        $this->travelTo($this->at('2026-01-01'));
        $this->binary()->place($this->members['A'], $this->members['N'], BinarySide::Left);
        $this->genealogy()->assignSponsor($this->members['B'], $this->members['N']);
        $this->record($this->members['A'], '10', 'a', at: $this->at('2026-02-01'));
        $this->record($this->members['B'], '20', 'b', at: $this->at('2026-02-01'));

        $this->assertSame('0', $this->network('N'));
    }

    public function test_it_reads_the_stored_member_not_the_instances_program(): void
    {
        $this->link('2026-01-01', 'N', 'A', 1);
        $this->record($this->members['A'], '10', 'a', at: $this->at('2026-02-01'));
        $this->members['N']->program_id = Program::factory()->create()->id;

        $this->assertSame('10', $this->network('N'));
    }

    public function test_one_query_serves_any_number_of_members_below(): void
    {
        $this->link('2026-01-01', 'N', 'A', 1);
        $this->record($this->members['A'], '1', 'a', at: $this->at('2026-02-01'));
        $small = $this->queriesFor(fn (): string => $this->network('N'));

        foreach (['B' => ['N', 2], 'C' => ['A', 1], 'D' => ['C', 3], 'X' => ['N', 3]] as $child => [$parent, $slot]) {
            $this->link('2026-01-01', $parent, $child, $slot);
            $this->record($this->members[$child], '1', strtolower($child), at: $this->at('2026-02-01'));
        }

        $large = $this->queriesFor(fn (): string => $this->network('N'));

        $this->assertSame('5', $this->network('N'));
        $this->assertSame($small, $large);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function refusedParameters(): array
    {
        return [
            'no type' => [[], 'requires the "type" parameter'],
            'an invalid type' => [['type' => 'Sales'], 'invalid "type"'],
            'a maximum depth of 0' => [['type' => 'sales', 'max_depth' => 0], 'invalid "max_depth"'],
            'a numeric string maximum depth' => [['type' => 'sales', 'max_depth' => '2'], 'invalid "max_depth"'],
            'a float maximum depth' => [['type' => 'sales', 'max_depth' => 2.0], 'invalid "max_depth"'],
            'a slot' => [['type' => 'sales', 'slot' => 1], 'does not accept "slot"'],
            'a width' => [['type' => 'sales', 'width' => 3], 'does not accept "width"'],
        ];
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    #[DataProvider('refusedParameters')]
    public function test_parameters_are_checked_not_ignored(array $parameters, string $reason): void
    {
        $this->expectException(InvalidMetricParameters::class);
        $this->expectExceptionMessage($reason);

        $this->engine()->resolve('matrix.network.volume', new MetricContext($this->members['N'], $parameters));
    }

    public function test_a_qualification_rule_reads_the_matrix_through_the_unchanged_engine(): void
    {
        $this->link('2026-01-01', 'N', 'A', 1);
        $this->link('2026-01-01', 'A', 'B', 2);
        $this->record($this->members['A'], '60', 'a', at: $this->at('2026-02-01'));
        $this->record($this->members['B'], '50', 'b', at: $this->at('2026-02-01'));

        $rule = $this->validatedRule(
            RuleDefinition::all(MetricCondition::of('matrix.network.volume', ['type' => 'sales', 'max_depth' => 1], '>=', '100')),
            $this->program->plans()->create(['code' => 'MAIN', 'name' => 'Main']),
        );
        $engine = $this->app->make(QualificationEngine::class);

        $this->assertFalse($engine->evaluate($rule, new QualificationContext($this->members['N']))->qualified);

        $this->record($this->members['A'], '40', 'a-more', at: $this->at('2026-02-02'));

        $this->assertTrue($engine->evaluate($rule, new QualificationContext($this->members['N']))->qualified);
    }

    /**
     * `$child` placed in `$slot` of `$parent`, from `$at`.
     */
    private function link(string $at, string $parent, string $child, int $slot): void
    {
        $this->travelTo($this->at($at));
        $this->matrix()->place($this->members[$child], $this->members[$parent], $slot);
        $this->travelBack();
    }

    private function network(string $member, ?string $from = null, ?string $until = null, ?int $maxDepth = null, string $type = 'sales'): string
    {
        return $this->engine()->resolve('matrix.network.volume', new MetricContext(
            $this->members[$member],
            ['type' => $type, ...($maxDepth === null ? [] : ['max_depth' => $maxDepth])],
            $from === null ? null : $this->at($from),
            $until === null ? null : $this->at($until),
        ))->value();
    }

    private function queriesFor(callable $read): int
    {
        $count = 0;
        DB::listen(static function (QueryExecuted $query) use (&$count): void {
            $count++;
        });
        $read();
        $counted = $count;
        DB::getEventDispatcher()?->forget(QueryExecuted::class);

        return $counted;
    }

    private function at(string $moment): CarbonImmutable
    {
        return CarbonImmutable::parse($moment);
    }

    private function engine(): MetricEngine
    {
        return $this->app->make(MetricEngine::class);
    }
}
