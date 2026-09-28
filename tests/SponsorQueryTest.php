<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use InvalidArgumentException;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;

/**
 * Alice
 * ├── Bob
 * │   ├── Diana
 * │   └── Edward
 * └── Charlie
 *     └── Fiona
 */
final class SponsorQueryTest extends DatabaseTestCase
{
    use BuildsGenealogies;

    public function test_ancestors_are_nearest_first_and_exclude_the_member(): void
    {
        $tree = $this->familyTree();

        $this->assertSame(['Bob@1', 'Alice@2'], $this->relatives($this->genealogy()->ancestors($tree['Diana'])));
        $this->assertSame(['Charlie@1', 'Alice@2'], $this->relatives($this->genealogy()->ancestors($tree['Fiona'])));
        $this->assertSame(['Alice@1'], $this->relatives($this->genealogy()->ancestors($tree['Bob'])));
        $this->assertSame([], $this->relatives($this->genealogy()->ancestors($tree['Alice'])));
    }

    public function test_descendants_are_nearest_first_and_exclude_the_member(): void
    {
        $tree = $this->familyTree();

        $this->assertSame(
            ['Bob@1', 'Charlie@1', 'Diana@2', 'Edward@2', 'Fiona@2'],
            $this->relatives($this->genealogy()->descendants($tree['Alice'])),
        );
        $this->assertSame(['Diana@1', 'Edward@1'], $this->relatives($this->genealogy()->descendants($tree['Bob'])));
        $this->assertSame(['Fiona@1'], $this->relatives($this->genealogy()->descendants($tree['Charlie'])));
        $this->assertSame([], $this->relatives($this->genealogy()->descendants($tree['Diana'])));
    }

    public function test_a_maximum_depth_limits_how_far_a_query_reaches(): void
    {
        $tree = $this->familyTree();

        $this->assertSame(['Bob@1', 'Charlie@1'], $this->relatives($this->genealogy()->descendants($tree['Alice'], maxDepth: 1)));
        $this->assertSame(['Bob@1'], $this->relatives($this->genealogy()->ancestors($tree['Diana'], maxDepth: 1)));
        $this->assertCount(5, $this->genealogy()->descendants($tree['Alice'], maxDepth: 2));
    }

    public function test_a_maximum_depth_below_one_is_refused(): void
    {
        $tree = $this->familyTree();

        $this->expectException(InvalidArgumentException::class);

        $this->genealogy()->descendants($tree['Alice'], maxDepth: 0);
    }

    public function test_direct_sponsor_and_direct_members(): void
    {
        $tree = $this->familyTree();

        $this->assertTrue($this->genealogy()->directSponsor($tree['Diana'])?->is($tree['Bob']));
        $this->assertNull($this->genealogy()->directSponsor($tree['Alice']));
        $this->assertSame(['Bob', 'Charlie'], $this->genealogy()->directMembers($tree['Alice'])->pluck('member_code')->all());
        $this->assertSame(['Diana', 'Edward'], $this->genealogy()->directMembers($tree['Bob'])->pluck('member_code')->all());
        $this->assertSame([], $this->genealogy()->directMembers($tree['Diana'])->all());
    }

    public function test_the_closure_holds_every_path_exactly_once(): void
    {
        $this->familyTree();

        $this->assertSame([
            'sponsor: Alice > Alice @0', 'sponsor: Alice > Bob @1', 'sponsor: Alice > Charlie @1',
            'sponsor: Alice > Diana @2', 'sponsor: Alice > Edward @2', 'sponsor: Alice > Fiona @2',
            'sponsor: Bob > Bob @0', 'sponsor: Bob > Diana @1', 'sponsor: Bob > Edward @1',
            'sponsor: Charlie > Charlie @0', 'sponsor: Charlie > Fiona @1',
            'sponsor: Diana > Diana @0', 'sponsor: Edward > Edward @0', 'sponsor: Fiona > Fiona @0',
        ], $this->genealogyState()['paths']);
    }

    public function test_an_existing_subtree_is_attached_whole(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob', 'Charlie', 'Diana');
        $this->sponsorTree($members, ['Bob' => ['Charlie'], 'Charlie' => ['Diana']]);

        $this->genealogy()->assignSponsor($members['Bob'], $members['Alice']);

        $this->assertSame([
            'sponsor: Alice > Alice @0', 'sponsor: Alice > Bob @1', 'sponsor: Alice > Charlie @2', 'sponsor: Alice > Diana @3',
            'sponsor: Bob > Bob @0', 'sponsor: Bob > Charlie @1', 'sponsor: Bob > Diana @2',
            'sponsor: Charlie > Charlie @0', 'sponsor: Charlie > Diana @1',
            'sponsor: Diana > Diana @0',
        ], $this->genealogyState()['paths']);
        $this->assertSame(['Charlie@1', 'Bob@2', 'Alice@3'], $this->relatives($this->genealogy()->ancestors($members['Diana'])));
    }

    public function test_reading_a_member_outside_the_tree_writes_nothing(): void
    {
        $this->familyTree();
        ['Loner' => $loner] = $this->members(Program::factory()->create(), 'Loner');
        $before = $this->genealogyState();

        $this->assertNull($this->genealogy()->directSponsor($loner));
        $this->assertSame([], $this->genealogy()->directMembers($loner)->all());
        $this->assertSame([], $this->relatives($this->genealogy()->ancestors($loner)));
        $this->assertSame([], $this->relatives($this->genealogy()->descendants($loner)));
        $this->assertSame($before, $this->genealogyState());
    }

    public function test_unrelated_roots_stay_separate(): void
    {
        $program = Program::factory()->create();
        $tree = $this->familyTree($program);
        $other = $this->members($program, 'Zed', 'Yara');
        $this->sponsorTree($other, ['Zed' => ['Yara']]);

        $this->assertNotContains('Yara@1', $this->relatives($this->genealogy()->descendants($tree['Alice'])));
        $this->assertCount(5, $this->genealogy()->descendants($tree['Alice']));
        $this->assertSame(['Zed@1'], $this->relatives($this->genealogy()->ancestors($other['Yara'])));
    }

    public function test_programs_do_not_mix(): void
    {
        $first = Program::factory()->create();
        $treeA = $this->familyTree($first);
        $this->familyTree(Program::factory()->create());

        $descendants = $this->genealogy()->descendants($treeA['Alice']);

        $this->assertCount(5, $descendants);
        $this->assertSame([$first->id], $descendants->map(static fn ($relative): string => $relative->member->program_id)->unique()->values()->all());
        $this->assertSame(['Bob', 'Charlie'], $this->genealogy()->directMembers($treeA['Alice'])->pluck('member_code')->all());
    }

    /**
     * @return array<string, Member>
     */
    private function familyTree(?Program $program = null): array
    {
        $members = $this->members($program ?? Program::factory()->create(), 'Alice', 'Bob', 'Charlie', 'Diana', 'Edward', 'Fiona');

        $this->sponsorTree($members, [
            'Alice' => ['Bob', 'Charlie'],
            'Bob' => ['Diana', 'Edward'],
            'Charlie' => ['Fiona'],
        ]);

        return $members;
    }
}
