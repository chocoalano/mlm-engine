<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Exceptions\InvalidMetricParameters;
use PandaBear\Mlm\Metrics\MetricContext;
use PandaBear\Mlm\Metrics\MetricEngine;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;
use PandaBear\Mlm\Tests\Concerns\RecordsVolume;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `sponsor.network.volume` and `placement.network.volume`: the volume of the
 * members below a member, each entry counted only if its member was below
 * when the activity happened — for a reversal, when the reversed activity
 * happened. The entry's own moment decides the period it falls in.
 */
final class NetworkVolumeMetricTest extends DatabaseTestCase
{
    use BuildsGenealogies;
    use RecordsVolume;

    /**
     * @return array<string, array{'sponsor'|'placement'}>
     */
    public static function trees(): array
    {
        return ['sponsor network' => ['sponsor'], 'placement network' => ['placement']];
    }

    #[DataProvider('trees')]
    public function test_activity_from_before_the_member_joined_never_counts(string $tree): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Charlie');

        $this->record($members['Charlie'], '100', 'jan', at: $this->at('2026-01-01 12:00:00'));
        $this->link($tree, $members, '2026-02-01 00:00:00', 'Alice', 'Charlie');
        $this->record($members['Charlie'], '200', 'mar', at: $this->at('2026-03-01 12:00:00'));

        $this->assertSame('200', $this->network($tree, $members['Alice']));
        $this->assertSame('0', $this->network($tree, $members['Alice'], from: '2026-01-01', until: '2026-02-01'));
        $this->assertSame('200', $this->network($tree, $members['Alice'], from: '2026-03-01', until: '2026-04-01'));
    }

    public function test_the_sponsor_and_placement_networks_are_independent(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob', 'Charlie');
        $this->link('sponsor', $members, '2026-01-01 00:00:00', 'Alice', 'Charlie');
        $this->link('placement', $members, '2026-01-01 00:00:00', 'Bob', 'Charlie');

        $this->record($members['Charlie'], '100', 'a', at: $this->at('2026-02-01 00:00:00'));

        $this->assertSame('100', $this->network('sponsor', $members['Alice']));
        $this->assertSame('0', $this->network('placement', $members['Alice']));
        $this->assertSame('100', $this->network('placement', $members['Bob']));
        $this->assertSame('0', $this->network('sponsor', $members['Bob']));
    }

    #[DataProvider('trees')]
    public function test_a_maximum_depth_counts_that_many_steps_down_and_never_the_member_itself(string $tree): void
    {
        $members = $this->line($tree);

        $this->record($members['A'], '1000', 'a', at: $this->at('2026-02-01 00:00:00'));
        $this->record($members['B'], '10', 'b', at: $this->at('2026-02-01 00:00:00'));
        $this->record($members['C'], '20', 'c', at: $this->at('2026-02-01 00:00:00'));
        $this->record($members['D'], '40', 'd', at: $this->at('2026-02-01 00:00:00'));

        $this->assertSame('10', $this->network($tree, $members['A'], maxDepth: 1));
        $this->assertSame('30', $this->network($tree, $members['A'], maxDepth: 2));
        $this->assertSame('70', $this->network($tree, $members['A'], maxDepth: 3));
        $this->assertSame('70', $this->network($tree, $members['A'], maxDepth: 4));
        $this->assertSame('70', $this->network($tree, $members['A']));
        $this->assertSame('60', $this->network($tree, $members['B']));
        $this->assertSame('0', $this->network($tree, $members['D']));
    }

    #[DataProvider('trees')]
    public function test_a_member_who_joined_deeper_later_counts_from_then_at_its_depth(string $tree): void
    {
        $members = $this->line($tree, 'E');

        $this->record($members['E'], '40', 'before', at: $this->at('2026-02-01 00:00:00'));
        $this->link($tree, $members, '2026-03-01 00:00:00', 'D', 'E');
        $this->record($members['E'], '80', 'after', at: $this->at('2026-04-01 00:00:00'));

        $this->assertSame('80', $this->network($tree, $members['A']));
        $this->assertSame('80', $this->network($tree, $members['A'], maxDepth: 4));
        $this->assertSame('0', $this->network($tree, $members['A'], maxDepth: 3));
        $this->assertSame('80', $this->network($tree, $members['D'], maxDepth: 1));
    }

    #[DataProvider('trees')]
    public function test_the_members_own_volume_and_its_reversals_never_count(string $tree): void
    {
        $members = $this->members(Program::factory()->create(), 'A', 'B');
        $this->link($tree, $members, '2026-01-01 00:00:00', 'A', 'B');

        $own = $this->record($members['A'], '1000', 'own', at: $this->at('2026-02-01 00:00:00'));
        $this->reverse($own, 'own-refund', at: $this->at('2026-03-01 00:00:00'));
        $this->record($members['B'], '5', 'b', at: $this->at('2026-02-01 00:00:00'));

        $this->assertSame('5', $this->network($tree, $members['A']));
        $this->assertSame('0', $this->network($tree, $members['A'], from: '2026-03-01'));
    }

    public function test_a_reversal_of_activity_from_before_the_member_joined_counts_for_no_one(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Charlie');

        $january = $this->record($members['Charlie'], '100', 'jan', at: $this->at('2026-01-10 00:00:00'));
        $this->link('sponsor', $members, '2026-03-01 00:00:00', 'Alice', 'Charlie');
        $this->reverse($january, 'jan-refund', at: $this->at('2026-04-10 00:00:00'));

        $this->assertSame('0', $this->network('sponsor', $members['Alice']));
        $this->assertSame('0', $this->network('sponsor', $members['Alice'], from: '2026-01-01', until: '2026-02-01'));
        $this->assertSame('0', $this->network('sponsor', $members['Alice'], from: '2026-04-01', until: '2026-05-01'));
    }

    public function test_a_reversal_is_clawed_back_in_its_own_period_from_the_same_upline(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Charlie');
        $this->link('sponsor', $members, '2026-01-01 00:00:00', 'Alice', 'Charlie');

        $sale = $this->record($members['Charlie'], '100', 'jan', at: $this->at('2026-01-15 00:00:00'));
        $this->reverse($sale, 'jan-refund', at: $this->at('2026-04-10 00:00:00'));

        $this->assertSame('100', $this->network('sponsor', $members['Alice'], from: '2026-01-01', until: '2026-02-01'));
        $this->assertSame('-100', $this->network('sponsor', $members['Alice'], from: '2026-04-01', until: '2026-05-01'));
        $this->assertSame('0', $this->network('sponsor', $members['Alice']));
    }

    #[DataProvider('trees')]
    public function test_an_ancestor_who_joined_later_receives_neither_the_activity_nor_its_reversal(string $tree): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob', 'Charlie');
        $this->link($tree, $members, '2026-01-01 00:00:00', 'Bob', 'Charlie');

        $sale = $this->record($members['Charlie'], '100', 'jan', at: $this->at('2026-01-15 00:00:00'));
        $this->link($tree, $members, '2026-03-01 00:00:00', 'Alice', 'Bob');
        $this->reverse($sale, 'jan-refund', at: $this->at('2026-04-10 00:00:00'));

        [$january, $april] = [['2026-01-01', '2026-02-01'], ['2026-04-01', '2026-05-01']];

        $this->assertSame('100', $this->network($tree, $members['Bob'], ...$january));
        $this->assertSame('-100', $this->network($tree, $members['Bob'], ...$april));
        $this->assertSame('0', $this->network($tree, $members['Bob']));

        $this->assertSame('0', $this->network($tree, $members['Alice'], ...$january));
        $this->assertSame('0', $this->network($tree, $members['Alice'], ...$april));
        $this->assertSame('0', $this->network($tree, $members['Alice']));

        // Activity after Alice joined is hers as well.
        $this->record($members['Charlie'], '50', 'may', at: $this->at('2026-05-01 00:00:00'));
        $this->assertSame('50', $this->network($tree, $members['Alice']));
        $this->assertSame('50', $this->network($tree, $members['Bob']));
    }

    public function test_an_activity_and_its_reversal_in_one_period_net_out(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Charlie');
        $this->link('sponsor', $members, '2026-01-01 00:00:00', 'Alice', 'Charlie');

        $sale = $this->record($members['Charlie'], '100', 'a', at: $this->at('2026-01-15 00:00:00'));
        $this->reverse($sale, 'a-refund', at: $this->at('2026-01-20 00:00:00'));
        $this->record($members['Charlie'], '30', 'b', at: $this->at('2026-01-25 00:00:00'));

        $this->assertSame('30', $this->network('sponsor', $members['Alice'], from: '2026-01-01', until: '2026-02-01'));
    }

    #[DataProvider('trees')]
    public function test_the_period_includes_its_start_and_excludes_its_end(string $tree): void
    {
        $members = $this->members(Program::factory()->create(), 'A', 'B');
        $this->link($tree, $members, '2026-01-01 00:00:00', 'A', 'B');

        $this->record($members['B'], '1', 'before', at: $this->at('2026-05-31 23:59:59'));
        $this->record($members['B'], '10', 'start', at: $this->at('2026-06-01 00:00:00'));
        $this->record($members['B'], '100', 'last', at: $this->at('2026-06-30 23:59:59'));
        $this->record($members['B'], '1000', 'end', at: $this->at('2026-07-01 00:00:00'));

        $this->assertSame('110', $this->network($tree, $members['A'], from: '2026-06-01', until: '2026-07-01'));
        $this->assertSame('1110', $this->network($tree, $members['A'], from: '2026-06-01'));
        $this->assertSame('111', $this->network($tree, $members['A'], until: '2026-07-01'));
        $this->assertSame('1111', $this->network($tree, $members['A']));
    }

    #[DataProvider('trees')]
    public function test_only_the_requested_type_counts(string $tree): void
    {
        $members = $this->members(Program::factory()->create(), 'A', 'B');
        $this->link($tree, $members, '2026-01-01 00:00:00', 'A', 'B');

        $this->record($members['B'], '10', 'sale', at: $this->at('2026-02-01 00:00:00'));
        $this->record($members['B'], '7', 'retail', type: 'retail', at: $this->at('2026-02-01 00:00:00'));

        $this->assertSame('10', $this->network($tree, $members['A'], type: 'sales'));
        $this->assertSame('7', $this->network($tree, $members['A'], type: 'retail'));
        $this->assertSame('0', $this->network($tree, $members['A'], type: 'wholesale'));
    }

    #[DataProvider('trees')]
    public function test_values_are_exact(string $tree): void
    {
        $members = $this->members(Program::factory()->create(), 'A', 'B', 'C');
        $this->link($tree, $members, '2026-01-01 00:00:00', 'A', 'B');
        $this->link($tree, $members, '2026-01-01 00:00:00', 'A', 'C');

        $this->record($members['B'], '0.1', 'b1', at: $this->at('2026-02-01 00:00:00'));
        $this->record($members['B'], '0.1', 'b2', at: $this->at('2026-02-01 00:00:00'));
        $this->record($members['C'], '0.1', 'c1', at: $this->at('2026-02-01 00:00:00'));

        $this->assertSame('0.3', $this->network($tree, $members['A']));
    }

    #[DataProvider('trees')]
    public function test_a_network_beyond_a_64_bit_count_of_millionths_is_exact(string $tree): void
    {
        if ($this->app->make('db')->connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite overflows SUM past 64 bits (ADR-010); MySQL and PostgreSQL widen it.');
        }

        $codes = array_map(static fn (int $i): string => "M{$i}", range(1, 10));
        $members = $this->members(Program::factory()->create(), 'Anchor', ...$codes);

        foreach ($codes as $code) {
            $this->link($tree, $members, '2026-01-01 00:00:00', 'Anchor', $code);
            $this->record($members[$code], '999999999999.999999', "large-{$code}", at: $this->at('2026-02-01 00:00:00'));
        }

        $total = $this->network($tree, $members['Anchor']);

        $this->assertSame('9999999999999.99999', $total);
    }

    #[DataProvider('trees')]
    public function test_a_member_outside_the_tree_has_an_empty_network(string $tree): void
    {
        $members = $this->members(Program::factory()->create(), 'Loner');
        $this->record($members['Loner'], '10', 'own', at: $this->at('2026-02-01 00:00:00'));

        $this->assertSame('0', $this->network($tree, $members['Loner']));
    }

    #[DataProvider('trees')]
    public function test_it_reads_the_stored_member_not_the_instances_program(string $tree): void
    {
        $members = $this->members(Program::factory()->create(), 'A', 'B');
        $this->link($tree, $members, '2026-01-01 00:00:00', 'A', 'B');
        $this->record($members['B'], '10', 'b', at: $this->at('2026-02-01 00:00:00'));

        // An unsaved change claiming another program.
        $members['A']->program_id = Program::factory()->create()->id;

        $this->assertSame('10', $this->network($tree, $members['A']));
    }

    #[DataProvider('trees')]
    public function test_volume_of_another_program_never_counts_even_through_a_raw_path(string $tree): void
    {
        $members = $this->members(Program::factory()->create(), 'A', 'B');
        $outsider = Member::factory()->create(['member_code' => 'Outsider']);
        $this->link($tree, $members, '2026-01-01 00:00:00', 'A', 'B');
        $this->record($members['B'], '10', 'b', at: $this->at('2026-02-01 00:00:00'));
        $this->record($outsider, '999', 'outsider', at: $this->at('2026-02-01 00:00:00'));

        // A path no supported write can make: across programs.
        DB::table('mlm_genealogy_paths')->insert([
            'tree_type' => $tree,
            'ancestor_id' => $members['A']->id,
            'descendant_id' => $outsider->id,
            'depth' => 1,
            'effective_from' => '2026-01-01 00:00:00',
        ]);

        $this->assertSame('10', $this->network($tree, $members['A']));
    }

    public function test_resolving_is_read_only_repeatable_and_independent_of_the_clock(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob', 'Charlie');
        $this->link('sponsor', $members, '2026-01-01 00:00:00', 'Alice', 'Bob');
        $this->link('placement', $members, '2026-03-01 00:00:00', 'Alice', 'Charlie');
        $sale = $this->record($members['Bob'], '12.5', 'b', at: $this->at('2026-02-01 00:00:00'));
        $this->reverse($sale, 'b-refund', at: $this->at('2026-05-01 00:00:00'));
        $this->record($members['Charlie'], '4', 'c', at: $this->at('2026-04-01 00:00:00'));

        $tables = ['mlm_volume_entries', 'mlm_sponsor_edges', 'mlm_placement_edges', 'mlm_genealogy_paths', 'mlm_plans', 'mlm_plan_versions'];
        $counts = static fn (): array => array_map(static fn (string $table): int => DB::table($table)->count(), $tables);
        $before = [$counts(), $this->volumeRows(), $this->sponsorState(), $this->placementState()];

        $results = [];

        foreach (['2025-01-01 00:00:00', '2026-02-15 00:00:00', '2030-12-31 23:59:59'] as $now) {
            $this->travelTo(CarbonImmutable::parse($now));

            $results[] = [
                $this->network('sponsor', $members['Alice']),
                $this->network('sponsor', $members['Alice'], from: '2026-02-01', until: '2026-03-01'),
                $this->network('placement', $members['Alice']),
            ];
        }

        $this->assertSame([['0', '12.5', '4'], ['0', '12.5', '4'], ['0', '12.5', '4']], $results);
        $this->assertSame($before, [$counts(), $this->volumeRows(), $this->sponsorState(), $this->placementState()]);
    }

    /**
     * @return array<string, array{string, array<string, mixed>, string}>
     */
    public static function refusedParameters(): array
    {
        $refusals = [
            'no type' => [[], 'requires the "type" parameter'],
            'an invalid type' => [['type' => 'Sales'], 'invalid "type"'],
            'a type that is not a string' => [['type' => 5], 'invalid "type"'],
            'a maximum depth of 0' => [['type' => 'sales', 'max_depth' => 0], 'invalid "max_depth"'],
            'a negative maximum depth' => [['type' => 'sales', 'max_depth' => -1], 'invalid "max_depth"'],
            'a fractional maximum depth' => [['type' => 'sales', 'max_depth' => 1.5], 'invalid "max_depth"'],
            'a whole float maximum depth' => [['type' => 'sales', 'max_depth' => 2.0], 'invalid "max_depth"'],
            'a numeric string maximum depth' => [['type' => 'sales', 'max_depth' => '2'], 'invalid "max_depth"'],
            'a boolean maximum depth' => [['type' => 'sales', 'max_depth' => true], 'invalid "max_depth"'],
            'an explicit null maximum depth' => [['type' => 'sales', 'max_depth' => null], 'invalid "max_depth"'],
            'maxDepth' => [['type' => 'sales', 'maxDepth' => 2], 'does not accept "maxDepth"'],
            'tree' => [['type' => 'sales', 'tree' => 'placement'], 'does not accept "tree"'],
            'include_self' => [['type' => 'sales', 'include_self' => true], 'does not accept "include_self"'],
            'levels' => [['type' => 'sales', 'levels' => 2], 'does not accept "levels"'],
        ];

        $cases = [];

        foreach (['sponsor.network.volume', 'placement.network.volume'] as $key) {
            foreach ($refusals as $name => [$parameters, $reason]) {
                $cases["{$key}: {$name}"] = [$key, $parameters, $reason];
            }
        }

        return $cases;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    #[DataProvider('refusedParameters')]
    public function test_parameters_are_checked_not_ignored(string $key, array $parameters, string $reason): void
    {
        $this->expectException(InvalidMetricParameters::class);
        $this->expectExceptionMessage($reason);

        $this->engine()->resolve($key, new MetricContext(Member::factory()->create(), $parameters));
    }

    /**
     * A > B > C > D, every link in effect from January.
     *
     * @param  'sponsor'|'placement'  $tree
     * @return array<string, Member>
     */
    private function line(string $tree, string ...$more): array
    {
        $members = $this->members(Program::factory()->create(), 'A', 'B', 'C', 'D', ...$more);

        $this->link($tree, $members, '2026-01-01 00:00:00', 'A', 'B');
        $this->link($tree, $members, '2026-01-01 00:00:00', 'B', 'C');
        $this->link($tree, $members, '2026-01-01 00:00:00', 'C', 'D');

        return $members;
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

    /**
     * @param  'sponsor'|'placement'  $tree
     */
    private function network(
        string $tree,
        Member $member,
        ?string $from = null,
        ?string $until = null,
        ?int $maxDepth = null,
        string $type = 'sales',
    ): string {
        return $this->engine()->resolve("{$tree}.network.volume", new MetricContext(
            $member,
            ['type' => $type, ...($maxDepth === null ? [] : ['max_depth' => $maxDepth])],
            $from === null ? null : CarbonImmutable::parse($from),
            $until === null ? null : CarbonImmutable::parse($until),
        ))->value();
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
