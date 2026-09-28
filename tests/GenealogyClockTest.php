<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A genealogy write is dated by the application clock, but never earlier
 * than the moments already in the two parts it joins. So the history written
 * live is the history migration 000009 rebuilds from the edges, even when the
 * clock goes back between writes.
 */
final class GenealogyClockTest extends DatabaseTestCase
{
    use BuildsGenealogies;

    private const MIGRATION = 'database/migrations/2026_09_28_000009_add_effective_from_to_mlm_genealogy_paths.php';

    /**
     * @return array<string, array{'sponsor'|'placement'}>
     */
    public static function trees(): array
    {
        return ['sponsor tree' => ['sponsor'], 'placement tree' => ['placement']];
    }

    #[DataProvider('trees')]
    public function test_a_subtree_joined_after_the_clock_went_back_keeps_its_later_moment(string $tree): void
    {
        $members = $this->members(Program::factory()->create(), 'A', 'B', 'C');

        $this->link($tree, $members, '2026-05-01 10:10:00', 'B', 'C');
        // The clock is now five minutes behind the edge already written.
        $this->link($tree, $members, '2026-05-01 10:05:00', 'A', 'B');

        $this->assertSame('2026-05-01 10:10:00', $this->edgeMoment($tree, $members['B']));
        $this->assertSame([
            'A > A @0' => '2026-05-01 10:10:00',
            'A > B @1' => '2026-05-01 10:10:00',
            'A > C @2' => '2026-05-01 10:10:00',
            'B > B @0' => '2026-05-01 10:10:00',
            'B > C @1' => '2026-05-01 10:10:00',
            'C > C @0' => '2026-05-01 10:10:00',
        ], $this->pathMoments($tree));
    }

    #[DataProvider('trees')]
    public function test_a_member_joined_below_a_line_written_later_takes_the_lines_moment(string $tree): void
    {
        $members = $this->members(Program::factory()->create(), 'A', 'B', 'C');

        $this->link($tree, $members, '2026-05-01 10:10:00', 'A', 'B');
        $this->link($tree, $members, '2026-05-01 10:05:00', 'B', 'C');

        $this->assertSame('2026-05-01 10:10:00', $this->edgeMoment($tree, $members['C']));
        $this->assertSame('2026-05-01 10:10:00', $this->pathMoments($tree)['A > C @2']);
        $this->assertSame('2026-05-01 10:10:00', $this->pathMoments($tree)['C > C @0']);
    }

    #[DataProvider('trees')]
    public function test_an_unrelated_part_of_the_program_does_not_delay_a_write(string $tree): void
    {
        $members = $this->members(Program::factory()->create(), 'A', 'B', 'X', 'Y');

        $this->link($tree, $members, '2026-05-01 10:10:00', 'X', 'Y');
        $this->link($tree, $members, '2026-05-01 10:05:00', 'A', 'B');

        $this->assertSame('2026-05-01 10:05:00', $this->edgeMoment($tree, $members['B']));
        $this->assertSame('2026-05-01 10:05:00', $this->pathMoments($tree)['A > B @1']);
    }

    #[DataProvider('trees')]
    public function test_a_floor_in_the_same_second_keeps_that_second(string $tree): void
    {
        $members = $this->members(Program::factory()->create(), 'A', 'B', 'C');

        $this->link($tree, $members, '2026-05-01 10:10:00.900000', 'B', 'C');
        $this->link($tree, $members, '2026-05-01 10:10:00.100000', 'A', 'B');

        $this->assertSame('2026-05-01 10:10:00', $this->edgeMoment($tree, $members['B']));
        $this->assertSame(['2026-05-01 10:10:00'], array_values(array_unique($this->pathMoments($tree))));
    }

    #[DataProvider('trees')]
    public function test_the_edge_and_its_record_timestamps_take_the_floored_moment(string $tree): void
    {
        $members = $this->members(Program::factory()->create(), 'A', 'B', 'C');

        $this->link($tree, $members, '2026-05-01 10:10:00', 'B', 'C');
        $this->link($tree, $members, '2026-05-01 10:05:00', 'A', 'B');

        $edge = DB::table($tree === 'sponsor' ? 'mlm_sponsor_edges' : 'mlm_placement_edges')->where('member_id', $members['B']->id)->first();

        $this->assertSame('2026-05-01 10:10:00', (string) $edge->created_at);
        $this->assertSame('2026-05-01 10:10:00', (string) $edge->updated_at);
    }

    public function test_history_written_with_a_wandering_clock_is_rebuilt_identically_from_the_edges(): void
    {
        $members = $this->members(Program::factory()->create(), 'A', 'B', 'C', 'D', 'E', 'F', 'X', 'Y');

        // The clock jumps back and forth between writes; both trees at once.
        $this->link('sponsor', $members, '2026-05-01 10:10:00', 'B', 'C');
        $this->link('placement', $members, '2026-05-01 09:30:00', 'F', 'A');
        $this->link('sponsor', $members, '2026-05-01 10:05:00', 'A', 'B');
        $this->link('placement', $members, '2026-05-01 09:00:00', 'A', 'B');
        $this->link('sponsor', $members, '2026-05-01 10:00:00', 'C', 'D');
        $this->link('sponsor', $members, '2026-05-01 11:00:00', 'A', 'E');
        $this->link('placement', $members, '2026-05-01 12:00:00', 'B', 'C');
        $this->link('sponsor', $members, '2026-05-01 09:00:00', 'E', 'F');
        $this->link('placement', $members, '2026-05-01 08:00:00', 'C', 'D');
        $this->link('sponsor', $members, '2026-05-01 08:00:00', 'X', 'Y');
        $this->link('placement', $members, '2026-05-01 12:00:00', 'X', 'Y');

        $live = [$this->pathMoments('sponsor'), $this->pathMoments('placement')];

        // Written live, floored where the clock went back; unrelated X -> Y
        // keeps its own clock.
        $this->assertSame('2026-05-01 10:10:00', $live[0]['A > D @3']);
        $this->assertSame('2026-05-01 11:00:00', $live[0]['A > F @2']);
        $this->assertSame('2026-05-01 08:00:00', $live[0]['X > Y @1']);
        $this->assertSame('2026-05-01 12:00:00', $live[1]['F > D @4']);

        $this->artisan('migrate:rollback', ['--path' => dirname(__DIR__).'/'.self::MIGRATION, '--realpath' => true])->assertSuccessful();
        $this->artisan('migrate')->assertSuccessful();

        $this->assertSame($live, [$this->pathMoments('sponsor'), $this->pathMoments('placement')]);
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
    private function edgeMoment(string $tree, Member $child): string
    {
        return $tree === 'sponsor'
            ? (string) DB::table('mlm_sponsor_edges')->where('member_id', $child->id)->value('assigned_at')
            : (string) DB::table('mlm_placement_edges')->where('member_id', $child->id)->value('placed_at');
    }
}
