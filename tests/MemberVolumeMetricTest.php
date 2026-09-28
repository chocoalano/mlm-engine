<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Exceptions\InvalidMetricParameters;
use PandaBear\Mlm\Metrics\MetricContext;
use PandaBear\Mlm\Metrics\MetricEngine;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;
use PandaBear\Mlm\Tests\Concerns\RecordsVolume;
use PHPUnit\Framework\Attributes\DataProvider;

final class MemberVolumeMetricTest extends DatabaseTestCase
{
    use BuildsGenealogies;
    use RecordsVolume;

    public function test_it_is_the_members_own_volume_of_the_requested_type(): void
    {
        $member = Member::factory()->create();
        $sameProgram = Member::factory()->for($member->program)->create();
        $otherProgram = Member::factory()->create();

        $this->record($member, '10', 'a');
        $this->record($member, '20', 'b');
        $this->record($member, '7', 'c', type: 'retail');
        $this->record($sameProgram, '100', 'd');
        $this->record($otherProgram, '1000', 'a');

        $this->assertSame('30', $this->volume($member, 'sales'));
        $this->assertSame('7', $this->volume($member, 'retail'));
        $this->assertSame('0', $this->volume($member, 'wholesale'));
        $this->assertSame('0', $this->volume(Member::factory()->create(), 'sales'));
    }

    public function test_reversals_net_out(): void
    {
        $member = Member::factory()->create();
        $this->record($member, '10', 'a');
        $this->reverse($this->record($member, '20', 'b'), 'r');

        $this->assertSame('10', $this->volume($member, 'sales'));
    }

    public function test_values_are_exact(): void
    {
        $member = Member::factory()->create();

        foreach (['a', 'b', 'c'] as $key) {
            $this->record($member, '0.1', $key);
        }

        $this->assertSame('0.3', $this->volume($member, 'sales'));
    }

    public function test_the_range_includes_its_start_excludes_its_end_and_matches_volume_totals(): void
    {
        $member = Member::factory()->create();
        $this->record($member, '1', 'before', at: CarbonImmutable::parse('2026-05-31 23:59:59'));
        $this->record($member, '10', 'start', at: CarbonImmutable::parse('2026-06-01 00:00:00'));
        $this->record($member, '1000', 'end', at: CarbonImmutable::parse('2026-07-01 00:00:00'));
        [$june, $july] = [CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-07-01')];

        $this->assertSame('10', $this->volume($member, 'sales', $june, $july));
        $this->assertSame('1010', $this->volume($member, 'sales', from: $june));
        $this->assertSame('11', $this->volume($member, 'sales', until: $july));

        foreach ([[$june, $july], [$june, null], [null, $july], [null, null]] as [$from, $until]) {
            $this->assertSame(
                $this->totals()->forMember($member, 'sales', $from, $until)->value(),
                $this->volume($member, 'sales', $from, $until),
            );
        }
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function refusedParameters(): array
    {
        return [
            'no type' => [[], 'requires the "type" parameter'],
            'an invalid type' => [['type' => 'Sales'], 'invalid "type"'],
            'a type that is not a string' => [['type' => 5], 'invalid "type"'],
            'a misspelt parameter' => [['typo_type' => 'sales'], 'does not accept "typo_type"'],
            'an extra parameter' => [['type' => 'sales', 'depth' => '2'], 'does not accept "depth"'],
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

        $this->engine()->resolve('member.volume', new MetricContext(Member::factory()->create(), $parameters));
    }

    public function test_it_reads_by_member_not_by_the_instances_program(): void
    {
        $member = Member::factory()->create();
        $this->record($member, '10', 'a');

        // An unsaved change claiming another program.
        $member->program_id = Program::factory()->create()->id;

        $this->assertSame('10', $this->volume($member, 'sales'));
    }

    public function test_the_members_genealogy_plays_no_part(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob', 'Charlie');
        $this->sponsorTree($members, ['Alice' => ['Bob']]);
        $this->placementTree($members, ['Alice' => ['Charlie']]);

        $this->record($members['Alice'], '5', 'alice');
        $this->record($members['Bob'], '500', 'bob');
        $this->record($members['Charlie'], '5000', 'charlie');

        $this->assertSame('5', $this->volume($members['Alice'], 'sales'));
    }

    public function test_resolving_is_read_only_and_repeatable(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob');
        $this->sponsorTree($members, ['Alice' => ['Bob']]);
        $this->placementTree($members, ['Alice' => ['Bob']]);
        $this->record($members['Alice'], '12.5', 'a');

        $tables = ['mlm_volume_entries', 'mlm_sponsor_edges', 'mlm_placement_edges', 'mlm_genealogy_paths', 'mlm_plans', 'mlm_plan_versions'];
        $counts = static fn (): array => array_map(static fn (string $table): int => DB::table($table)->count(), $tables);
        $before = [$counts(), $this->volumeRows()];

        $first = $this->volume($members['Alice'], 'sales');
        $second = $this->volume($members['Alice'], 'sales');

        $this->assertSame('12.5', $first);
        $this->assertSame($first, $second);
        $this->assertSame($before, [$counts(), $this->volumeRows()]);
    }

    private function volume(Member $member, string $type, ?DateTimeInterface $from = null, ?DateTimeInterface $until = null): string
    {
        return $this->engine()->resolve('member.volume', new MetricContext($member, ['type' => $type], $from, $until))->value();
    }

    private function engine(): MetricEngine
    {
        return $this->app->make(MetricEngine::class);
    }
}
