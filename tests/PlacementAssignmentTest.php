<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Exceptions\InvalidPlacementAssignment;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\PlacementEdge;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;
use PHPUnit\Framework\Attributes\DataProvider;

final class PlacementAssignmentTest extends DatabaseTestCase
{
    use BuildsGenealogies;

    private const EMPTY = ['edges' => [], 'paths' => []];

    public function test_a_root_member_receives_its_first_placement_parent(): void
    {
        ['Alice' => $alice, 'Bob' => $bob] = $this->members(Program::factory()->create(), 'Alice', 'Bob');

        $edge = $this->placement()->place($bob, $alice);

        $this->assertInstanceOf(PlacementEdge::class, $edge);
        $this->assertTrue($edge->member->is($bob));
        $this->assertTrue($edge->parent->is($alice));
        $this->assertTrue($this->placement()->directParent($bob)?->is($alice));
        $this->assertNull($this->placement()->directParent($alice));
    }

    public function test_it_records_when_the_member_was_placed(): void
    {
        ['Alice' => $alice, 'Bob' => $bob] = $this->members(Program::factory()->create(), 'Alice', 'Bob');

        $this->travelTo('2026-04-01 08:15:00');
        $edge = $this->placement()->place($bob, $alice);

        $this->assertInstanceOf(CarbonImmutable::class, $edge->placed_at);
        $this->assertSame('2026-04-01 08:15:00', $edge->placed_at->format('Y-m-d H:i:s'));
    }

    public function test_a_member_needs_no_placement(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob');

        foreach ($members as $member) {
            $this->assertNull($this->placement()->directParent($member));
        }

        $this->assertSame(self::EMPTY, $this->placementState());
    }

    public function test_a_program_can_have_several_placement_roots(): void
    {
        $members = $this->members(Program::factory()->create(), 'RootA', 'RootB', 'RootC', 'Xavier', 'Yara');

        $this->placementTree($members, ['RootA' => ['Xavier'], 'RootB' => ['Yara']]);

        foreach (['RootA', 'RootB', 'RootC'] as $root) {
            $this->assertNull($this->placement()->directParent($members[$root]), "{$root} is not a root.");
        }

        $this->assertSame(['Xavier@1'], $this->relatives($this->placement()->descendants($members['RootA'])));
        $this->assertSame(['Yara@1'], $this->relatives($this->placement()->descendants($members['RootB'])));
        $this->assertSame([], $this->relatives($this->placement()->descendants($members['RootC'])));
        $this->assertSame(['RootA@1'], $this->relatives($this->placement()->ancestors($members['Xavier'])));
    }

    public function test_a_member_keeps_its_one_placement_parent(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob', 'Charlie');
        $this->placementTree($members, ['Alice' => ['Bob']]);
        $before = $this->placementState();

        $this->assertRefused(
            fn () => $this->placement()->place($members['Bob'], $members['Charlie']),
            'already placed',
            $before,
        );
        $this->assertTrue($this->placement()->directParent($members['Bob'])?->is($members['Alice']));
    }

    public function test_a_parent_can_have_any_number_of_members_placed_under_it(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob', 'Charlie', 'Diana', 'Edward', 'Fiona');

        $this->placementTree($members, ['Alice' => ['Bob', 'Charlie', 'Diana', 'Edward', 'Fiona']]);

        $this->assertSame(
            ['Bob', 'Charlie', 'Diana', 'Edward', 'Fiona'],
            $this->placement()->directChildren($members['Alice'])->pluck('member_code')->all(),
        );
    }

    public function test_member_and_parent_must_share_a_program(): void
    {
        ['Alice' => $alice] = $this->members(Program::factory()->create(), 'Alice');
        ['Bob' => $bob] = $this->members(Program::factory()->create(), 'Bob');

        $this->assertRefused(
            fn () => $this->placement()->place($bob, $alice),
            'placement stays within one program',
            self::EMPTY,
        );
    }

    public function test_a_member_cannot_be_placed_under_itself(): void
    {
        ['Alice' => $alice] = $this->members(Program::factory()->create(), 'Alice');

        $this->assertRefused(
            fn () => $this->placement()->place($alice, $alice),
            'cannot be placed under itself',
            self::EMPTY,
        );
    }

    /**
     * @return array<string, array{array<string, list<string>>, string, string}>
     */
    public static function cycles(): array
    {
        return [
            'two members' => [['A' => ['B']], 'A', 'B'],
            'a deep chain' => [['A' => ['B'], 'B' => ['C'], 'C' => ['D']], 'A', 'D'],
            'down a branch' => [['A' => ['B', 'C'], 'C' => ['D', 'E']], 'A', 'E'],
        ];
    }

    /**
     * @param  array<string, list<string>>  $tree
     */
    #[DataProvider('cycles')]
    public function test_a_parent_from_the_members_own_subtree_is_refused(array $tree, string $member, string $parent): void
    {
        $members = $this->members(Program::factory()->create(), 'A', 'B', 'C', 'D', 'E');
        $this->placementTree($members, $tree);
        $before = $this->placementState();

        $this->assertRefused(
            fn () => $this->placement()->place($members[$member], $members[$parent]),
            'would make a cycle',
            $before,
        );
    }

    public function test_a_stale_instance_cannot_bypass_the_one_parent_rule(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob', 'Charlie');
        $staleBob = Member::findOrFail($members['Bob']->id);

        $this->placement()->place($members['Bob'], $members['Alice']);

        $this->expectException(InvalidPlacementAssignment::class);

        $this->placement()->place($staleBob, $members['Charlie']);
    }

    public function test_the_program_is_read_from_the_database_not_the_instance(): void
    {
        $programA = Program::factory()->create();
        ['Alice' => $alice] = $this->members($programA, 'Alice');
        ['Bob' => $bob] = $this->members(Program::factory()->create(), 'Bob');

        // An unsaved change claiming Bob is in Alice's program.
        $bob->program_id = $programA->id;

        $this->assertRefused(
            fn () => $this->placement()->place($bob, $alice),
            'placement stays within one program',
            self::EMPTY,
        );
    }

    public function test_a_failed_placement_leaves_nothing_behind(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob', 'Charlie');
        $this->placementTree($members, ['Bob' => ['Charlie']]);

        // Corruption simulation: a placement path the attachment is about to
        // write is already there, so the path insert fails on uniqueness
        // after the edge and Alice's self path have been written — and the
        // transaction has to undo both.
        DB::table('mlm_genealogy_paths')->insert([
            'tree_type' => 'placement',
            'ancestor_id' => $members['Alice']->id,
            'descendant_id' => $members['Charlie']->id,
            'depth' => 2,
        ]);
        $before = $this->placementState();

        try {
            $this->placement()->place($members['Bob'], $members['Alice']);
            $this->fail('The duplicate path did not stop the placement.');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame($before, $this->placementState());
        }
    }

    /**
     * @param  callable(): mixed  $placement
     * @param  array{edges: list<string>, paths: list<string>}  $unchanged
     */
    private function assertRefused(callable $placement, string $reason, array $unchanged): void
    {
        try {
            $placement();
            $this->fail("The placement was not refused ({$reason}).");
        } catch (InvalidPlacementAssignment $exception) {
            $this->assertStringContainsString($reason, $exception->getMessage());
            $this->assertSame($unchanged, $this->placementState());
        }
    }
}
