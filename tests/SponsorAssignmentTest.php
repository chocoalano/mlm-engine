<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Exceptions\InvalidSponsorAssignment;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Models\SponsorEdge;
use PandaBear\Mlm\Tests\Concerns\BuildsSponsorTrees;
use PHPUnit\Framework\Attributes\DataProvider;

final class SponsorAssignmentTest extends DatabaseTestCase
{
    use BuildsSponsorTrees;

    public function test_a_root_member_receives_its_first_sponsor(): void
    {
        ['Alice' => $alice, 'Bob' => $bob] = $this->members(Program::factory()->create(), 'Alice', 'Bob');

        $edge = $this->genealogy()->assignSponsor($bob, $alice);

        $this->assertInstanceOf(SponsorEdge::class, $edge);
        $this->assertTrue($edge->member->is($bob));
        $this->assertTrue($edge->sponsor->is($alice));
        $this->assertTrue($this->genealogy()->directSponsor($bob)?->is($alice));
        $this->assertNull($this->genealogy()->directSponsor($alice));
    }

    public function test_it_records_when_the_sponsor_was_assigned(): void
    {
        ['Alice' => $alice, 'Bob' => $bob] = $this->members(Program::factory()->create(), 'Alice', 'Bob');

        $this->travelTo('2026-03-01 10:00:00');
        $edge = $this->genealogy()->assignSponsor($bob, $alice);

        $this->assertInstanceOf(CarbonImmutable::class, $edge->assigned_at);
        $this->assertSame('2026-03-01 10:00:00', $edge->assigned_at->format('Y-m-d H:i:s'));
    }

    public function test_a_member_needs_no_sponsor(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob', 'Charlie');

        foreach ($members as $member) {
            $this->assertNull($this->genealogy()->directSponsor($member));
        }

        $this->assertSame(['edges' => [], 'paths' => []], $this->genealogyState());
    }

    public function test_a_program_can_have_several_roots(): void
    {
        $members = $this->members(Program::factory()->create(), 'RootA', 'RootB', 'Xavier', 'Yara');

        $this->sponsorTree($members, ['RootA' => ['Xavier'], 'RootB' => ['Yara']]);

        $this->assertNull($this->genealogy()->directSponsor($members['RootA']));
        $this->assertNull($this->genealogy()->directSponsor($members['RootB']));
        $this->assertSame(['Xavier@1'], $this->relatives($this->genealogy()->descendants($members['RootA'])));
        $this->assertSame(['Yara@1'], $this->relatives($this->genealogy()->descendants($members['RootB'])));
    }

    public function test_a_member_keeps_its_one_sponsor(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob', 'Charlie');
        $this->sponsorTree($members, ['Alice' => ['Bob']]);
        $before = $this->genealogyState();

        $this->assertRefused(
            fn () => $this->genealogy()->assignSponsor($members['Bob'], $members['Charlie']),
            'already sponsored',
            $before,
        );
        $this->assertTrue($this->genealogy()->directSponsor($members['Bob'])?->is($members['Alice']));
    }

    public function test_a_sponsor_can_sponsor_any_number_of_members(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob', 'Charlie', 'Diana', 'Edward', 'Fiona');

        $this->sponsorTree($members, ['Alice' => ['Bob', 'Charlie', 'Diana', 'Edward', 'Fiona']]);

        $this->assertSame(
            ['Bob', 'Charlie', 'Diana', 'Edward', 'Fiona'],
            $this->genealogy()->directMembers($members['Alice'])->pluck('member_code')->all(),
        );
    }

    public function test_sponsor_and_member_must_share_a_program(): void
    {
        ['Alice' => $alice] = $this->members(Program::factory()->create(), 'Alice');
        ['Bob' => $bob] = $this->members(Program::factory()->create(), 'Bob');

        $this->assertRefused(
            fn () => $this->genealogy()->assignSponsor($bob, $alice),
            'sponsorship stays within one program',
            ['edges' => [], 'paths' => []],
        );
    }

    public function test_a_member_cannot_sponsor_itself(): void
    {
        ['Alice' => $alice] = $this->members(Program::factory()->create(), 'Alice');

        $this->assertRefused(
            fn () => $this->genealogy()->assignSponsor($alice, $alice),
            'cannot sponsor itself',
            ['edges' => [], 'paths' => []],
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
    public function test_a_sponsor_from_the_members_own_subtree_is_refused(array $tree, string $member, string $sponsor): void
    {
        $members = $this->members(Program::factory()->create(), 'A', 'B', 'C', 'D', 'E');
        $this->sponsorTree($members, $tree);
        $before = $this->genealogyState();

        $this->assertRefused(
            fn () => $this->genealogy()->assignSponsor($members[$member], $members[$sponsor]),
            'would make a cycle',
            $before,
        );
    }

    public function test_a_stale_instance_cannot_bypass_the_one_sponsor_rule(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob', 'Charlie');
        $staleBob = Member::findOrFail($members['Bob']->id);

        $this->genealogy()->assignSponsor($members['Bob'], $members['Alice']);

        $this->expectException(InvalidSponsorAssignment::class);

        $this->genealogy()->assignSponsor($staleBob, $members['Charlie']);
    }

    public function test_the_program_is_read_from_the_database_not_the_instance(): void
    {
        $programA = Program::factory()->create();
        ['Alice' => $alice] = $this->members($programA, 'Alice');
        ['Bob' => $bob] = $this->members(Program::factory()->create(), 'Bob');

        // An unsaved change claiming Bob is in Alice's program.
        $bob->program_id = $programA->id;

        $this->assertRefused(
            fn () => $this->genealogy()->assignSponsor($bob, $alice),
            'sponsorship stays within one program',
            ['edges' => [], 'paths' => []],
        );
    }

    public function test_a_failed_assignment_leaves_nothing_behind(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob', 'Charlie');
        $this->sponsorTree($members, ['Bob' => ['Charlie']]);

        // A path the attachment is about to write, already there: the insert
        // of the new paths fails on the path's uniqueness after the edge and
        // Alice's own path have been written, and the transaction undoes them.
        DB::table('mlm_genealogy_paths')->insert([
            'tree_type' => 'sponsor',
            'ancestor_id' => $members['Alice']->id,
            'descendant_id' => $members['Charlie']->id,
            'depth' => 2,
        ]);
        $before = $this->genealogyState();

        try {
            $this->genealogy()->assignSponsor($members['Bob'], $members['Alice']);
            $this->fail('The duplicate path did not stop the assignment.');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame($before, $this->genealogyState());
        }
    }

    /**
     * @param  callable(): mixed  $assignment
     * @param  array{edges: list<string>, paths: list<string>}  $unchanged
     */
    private function assertRefused(callable $assignment, string $reason, array $unchanged): void
    {
        try {
            $assignment();
            $this->fail("The assignment was not refused ({$reason}).");
        } catch (InvalidSponsorAssignment $exception) {
            $this->assertStringContainsString($reason, $exception->getMessage());
            $this->assertSame($unchanged, $this->genealogyState());
        }
    }
}
