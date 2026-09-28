<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use InvalidArgumentException;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;

/**
 * Placed as:
 *
 * Alice
 * ├── Bob
 * │   ├── Diana
 * │   └── Edward
 * └── Charlie
 *     └── Fiona
 */
final class PlacementQueryTest extends DatabaseTestCase
{
    use BuildsGenealogies;

    public function test_ancestors_are_nearest_first_and_exclude_the_member(): void
    {
        $tree = $this->placedTree();

        $this->assertSame(['Bob@1', 'Alice@2'], $this->relatives($this->placement()->ancestors($tree['Diana'])));
        $this->assertSame(['Charlie@1', 'Alice@2'], $this->relatives($this->placement()->ancestors($tree['Fiona'])));
        $this->assertSame([], $this->relatives($this->placement()->ancestors($tree['Alice'])));
    }

    public function test_descendants_are_nearest_first_and_exclude_the_member(): void
    {
        $tree = $this->placedTree();

        $this->assertSame(
            ['Bob@1', 'Charlie@1', 'Diana@2', 'Edward@2', 'Fiona@2'],
            $this->relatives($this->placement()->descendants($tree['Alice'])),
        );
        $this->assertSame(['Diana@1', 'Edward@1'], $this->relatives($this->placement()->descendants($tree['Bob'])));
        $this->assertSame([], $this->relatives($this->placement()->descendants($tree['Fiona'])));
    }

    public function test_a_maximum_depth_limits_how_far_a_query_reaches(): void
    {
        $tree = $this->placedTree();

        $this->assertSame(['Bob@1', 'Charlie@1'], $this->relatives($this->placement()->descendants($tree['Alice'], maxDepth: 1)));
        $this->assertSame(['Bob@1'], $this->relatives($this->placement()->ancestors($tree['Diana'], maxDepth: 1)));
    }

    public function test_a_maximum_depth_below_one_is_refused(): void
    {
        $tree = $this->placedTree();

        $this->expectException(InvalidArgumentException::class);

        $this->placement()->ancestors($tree['Diana'], maxDepth: 0);
    }

    public function test_direct_parent_and_direct_children(): void
    {
        $tree = $this->placedTree();

        $this->assertTrue($this->placement()->directParent($tree['Diana'])?->is($tree['Bob']));
        $this->assertNull($this->placement()->directParent($tree['Alice']));
        $this->assertSame(['Bob', 'Charlie'], $this->placement()->directChildren($tree['Alice'])->pluck('member_code')->all());
        $this->assertSame(['Diana', 'Edward'], $this->placement()->directChildren($tree['Bob'])->pluck('member_code')->all());
        $this->assertSame([], $this->placement()->directChildren($tree['Diana'])->all());
    }

    public function test_direct_children_are_in_the_order_they_were_placed(): void
    {
        $members = $this->members(Program::factory()->create(), 'Parent', 'Early', 'Late');

        // Placed out of creation order, a minute apart.
        $this->travelTo('2026-05-01 09:00:00');
        $this->placement()->place($members['Late'], $members['Parent']);
        $this->travelTo('2026-05-01 09:01:00');
        $this->placement()->place($members['Early'], $members['Parent']);

        $this->assertSame(['Late', 'Early'], $this->placement()->directChildren($members['Parent'])->pluck('member_code')->all());
    }

    public function test_the_closure_holds_every_placement_path_exactly_once(): void
    {
        $this->placedTree();

        $this->assertSame([
            'Alice > Alice @0', 'Alice > Bob @1', 'Alice > Charlie @1',
            'Alice > Diana @2', 'Alice > Edward @2', 'Alice > Fiona @2',
            'Bob > Bob @0', 'Bob > Diana @1', 'Bob > Edward @1',
            'Charlie > Charlie @0', 'Charlie > Fiona @1',
            'Diana > Diana @0', 'Edward > Edward @0', 'Fiona > Fiona @0',
        ], $this->placementState()['paths']);
    }

    public function test_an_existing_placement_subtree_is_attached_whole(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob', 'Charlie', 'Diana');
        $this->placementTree($members, ['Bob' => ['Charlie'], 'Charlie' => ['Diana']]);

        $this->placement()->place($members['Bob'], $members['Alice']);

        $this->assertSame([
            'Alice > Alice @0', 'Alice > Bob @1', 'Alice > Charlie @2', 'Alice > Diana @3',
            'Bob > Bob @0', 'Bob > Charlie @1', 'Bob > Diana @2',
            'Charlie > Charlie @0', 'Charlie > Diana @1',
            'Diana > Diana @0',
        ], $this->placementState()['paths']);
        $this->assertSame(['Charlie@1', 'Bob@2', 'Alice@3'], $this->relatives($this->placement()->ancestors($members['Diana'])));
        $this->assertSame(['edges' => [], 'paths' => []], $this->sponsorState());
    }

    public function test_reading_a_member_outside_the_tree_writes_nothing(): void
    {
        $this->placedTree();
        ['Loner' => $loner] = $this->members(Program::factory()->create(), 'Loner');
        $before = [$this->placementState(), $this->sponsorState()];

        $this->assertNull($this->placement()->directParent($loner));
        $this->assertSame([], $this->placement()->directChildren($loner)->all());
        $this->assertSame([], $this->relatives($this->placement()->ancestors($loner)));
        $this->assertSame([], $this->relatives($this->placement()->descendants($loner)));
        $this->assertSame($before, [$this->placementState(), $this->sponsorState()]);
    }

    public function test_programs_do_not_mix(): void
    {
        $first = Program::factory()->create();
        $treeA = $this->placedTree($first);
        $this->placedTree(Program::factory()->create());

        $descendants = $this->placement()->descendants($treeA['Alice']);

        $this->assertCount(5, $descendants);
        $this->assertSame([$first->id], $descendants->map(static fn ($relative): string => $relative->member->program_id)->unique()->values()->all());
        $this->assertSame(['Bob', 'Charlie'], $this->placement()->directChildren($treeA['Alice'])->pluck('member_code')->all());
    }

    /**
     * @return array<string, Member>
     */
    private function placedTree(?Program $program = null): array
    {
        $members = $this->members($program ?? Program::factory()->create(), 'Alice', 'Bob', 'Charlie', 'Diana', 'Edward', 'Fiona');

        $this->placementTree($members, [
            'Alice' => ['Bob', 'Charlie'],
            'Bob' => ['Diana', 'Edward'],
            'Charlie' => ['Fiona'],
        ]);

        return $members;
    }
}
