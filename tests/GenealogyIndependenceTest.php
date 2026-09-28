<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;

/**
 * Sponsorship and placement are two graphs over the same members. Neither
 * reads, writes nor implies the other, and their paths never leak across
 * `tree_type`.
 */
final class GenealogyIndependenceTest extends DatabaseTestCase
{
    use BuildsGenealogies;

    public function test_a_member_can_be_sponsored_by_one_member_and_placed_under_another(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob', 'Charlie');

        $this->genealogy()->assignSponsor($members['Charlie'], $members['Alice']);
        $this->placement()->place($members['Charlie'], $members['Bob']);

        $this->assertTrue($this->genealogy()->directSponsor($members['Charlie'])?->is($members['Alice']));
        $this->assertTrue($this->placement()->directParent($members['Charlie'])?->is($members['Bob']));

        $this->assertSame([
            'edges' => ['Alice > Charlie'],
            'paths' => ['Alice > Alice @0', 'Alice > Charlie @1', 'Charlie > Charlie @0'],
        ], $this->sponsorState());
        $this->assertSame([
            'edges' => ['Bob > Charlie'],
            'paths' => ['Bob > Bob @0', 'Bob > Charlie @1', 'Charlie > Charlie @0'],
        ], $this->placementState());
    }

    public function test_sponsoring_a_member_does_not_place_it(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob');

        $this->genealogy()->assignSponsor($members['Bob'], $members['Alice']);

        $this->assertNull($this->placement()->directParent($members['Bob']));
        $this->assertSame([], $this->placement()->directChildren($members['Alice'])->all());
        $this->assertSame([], $this->relatives($this->placement()->ancestors($members['Bob'])));
        $this->assertSame(['edges' => [], 'paths' => []], $this->placementState());
    }

    public function test_placing_a_member_does_not_sponsor_it(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob');

        $this->placement()->place($members['Bob'], $members['Alice']);

        $this->assertNull($this->genealogy()->directSponsor($members['Bob']));
        $this->assertSame([], $this->genealogy()->directMembers($members['Alice'])->all());
        $this->assertSame([], $this->relatives($this->genealogy()->ancestors($members['Bob'])));
        $this->assertSame(['edges' => [], 'paths' => []], $this->sponsorState());
    }

    public function test_the_two_trees_can_run_in_opposite_directions(): void
    {
        $members = $this->members(Program::factory()->create(), 'A', 'B', 'C');

        // Sponsored downwards A → B → C; placed the other way up, C → B → A.
        // Each cycle check only sees its own tree, so neither refuses.
        $this->sponsorTree($members, ['A' => ['B'], 'B' => ['C']]);
        $this->placementTree($members, ['C' => ['B'], 'B' => ['A']]);

        $this->assertSame(['B@1', 'A@2'], $this->relatives($this->genealogy()->ancestors($members['C'])));
        $this->assertSame([], $this->relatives($this->placement()->ancestors($members['C'])));
        $this->assertSame(['B@1', 'C@2'], $this->relatives($this->placement()->ancestors($members['A'])));
        $this->assertSame([], $this->relatives($this->genealogy()->ancestors($members['A'])));
        $this->assertSame(['B@1', 'C@2'], $this->relatives($this->genealogy()->descendants($members['A'])));
        $this->assertSame(['B@1', 'A@2'], $this->relatives($this->placement()->descendants($members['C'])));
    }

    public function test_placements_leave_the_sponsor_tree_untouched(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob', 'Charlie', 'Diana');
        $this->sponsorTree($members, ['Alice' => ['Bob', 'Charlie'], 'Charlie' => ['Diana']]);
        $sponsors = $this->sponsorState();

        $this->placementTree($members, ['Diana' => ['Charlie'], 'Charlie' => ['Bob', 'Alice']]);

        $this->assertSame($sponsors, $this->sponsorState());
    }

    public function test_sponsorships_leave_the_placement_tree_untouched(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob', 'Charlie', 'Diana');
        $this->placementTree($members, ['Alice' => ['Bob', 'Charlie'], 'Charlie' => ['Diana']]);
        $placements = $this->placementState();

        $this->sponsorTree($members, ['Diana' => ['Charlie'], 'Charlie' => ['Bob', 'Alice']]);

        $this->assertSame($placements, $this->placementState());
    }
}
