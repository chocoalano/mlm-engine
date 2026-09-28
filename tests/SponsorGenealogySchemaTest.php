<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Exceptions\InvalidSponsorAssignment;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Models\SponsorEdge;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;

/**
 * The database backs the genealogy's local invariants on its own — one
 * sponsor edge per member, one path per pair, foreign keys — and the edge
 * model cannot be used to write around the service. None of this makes a raw
 * edge write cycle-safe: graph correctness belongs to SponsorGenealogy.
 */
final class SponsorGenealogySchemaTest extends DatabaseTestCase
{
    use BuildsGenealogies;

    public function test_the_database_allows_one_sponsor_edge_per_member(): void
    {
        $members = $this->sponsoredPair('Charlie');

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('mlm_sponsor_edges')->insert([
            'id' => (new SponsorEdge)->newUniqueId(),
            'member_id' => $members['Bob']->id,
            'sponsor_id' => $members['Charlie']->id,
            'assigned_at' => now(),
        ]);
    }

    public function test_the_database_refuses_a_duplicate_path(): void
    {
        $members = $this->sponsoredPair();

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('mlm_genealogy_paths')->insert([
            'tree_type' => 'sponsor',
            'ancestor_id' => $members['Alice']->id,
            'descendant_id' => $members['Bob']->id,
            'depth' => 1,
        ]);
    }

    public function test_a_sponsored_member_cannot_be_deleted(): void
    {
        $members = $this->sponsoredPair();

        $this->expectException(QueryException::class);

        $members['Bob']->delete();
    }

    public function test_a_sponsor_cannot_be_deleted(): void
    {
        $members = $this->sponsoredPair();

        $this->expectException(QueryException::class);

        $members['Alice']->delete();
    }

    public function test_a_member_with_genealogy_paths_cannot_be_deleted(): void
    {
        $members = $this->sponsoredPair();

        // Without the edge, only the paths still reference Bob.
        DB::table('mlm_sponsor_edges')->delete();

        $this->expectException(QueryException::class);

        $members['Bob']->delete();
    }

    public function test_a_member_outside_the_genealogy_can_still_be_deleted(): void
    {
        $this->sponsoredPair();
        $loner = Member::factory()->create();

        $loner->delete();

        $this->assertModelMissing($loner);
    }

    public function test_an_edge_cannot_be_created_through_the_model(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob');

        $this->expectException(InvalidSponsorAssignment::class);

        (new SponsorEdge)->forceFill([
            'member_id' => $members['Bob']->id,
            'sponsor_id' => $members['Alice']->id,
            'assigned_at' => now(),
        ])->save();
    }

    public function test_an_edge_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        new SponsorEdge(['member_id' => 'x', 'sponsor_id' => 'y']);
    }

    public function test_an_edge_cannot_be_changed_or_deleted_through_the_model(): void
    {
        $members = $this->sponsoredPair('Charlie');
        $edge = SponsorEdge::sole();

        foreach ([
            'update' => fn () => $edge->forceFill(['sponsor_id' => $members['Charlie']->id])->save(),
            'delete' => fn () => $edge->delete(),
        ] as $operation => $write) {
            try {
                $write();
                $this->fail("An edge {$operation} went through the model.");
            } catch (InvalidSponsorAssignment) {
                $this->assertSame(['Alice > Bob'], $this->genealogyState()['edges']);
            }
        }
    }

    /**
     * Alice sponsors Bob; any other codes are members without a sponsor.
     *
     * @return array<string, Member>
     */
    private function sponsoredPair(string ...$others): array
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob', ...$others);
        $this->sponsorTree($members, ['Alice' => ['Bob']]);

        return $members;
    }
}
