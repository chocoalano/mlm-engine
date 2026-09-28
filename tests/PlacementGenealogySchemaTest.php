<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Exceptions\InvalidPlacementAssignment;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\PlacementEdge;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;

/**
 * The database backs placement's local invariants on its own — one placement
 * edge per member, one path per pair and tree, foreign keys — and the edge
 * model cannot be used to write around the service. None of this makes a raw
 * edge write cycle-safe: graph correctness belongs to PlacementGenealogy.
 */
final class PlacementGenealogySchemaTest extends DatabaseTestCase
{
    use BuildsGenealogies;

    public function test_the_database_allows_one_placement_edge_per_member(): void
    {
        $members = $this->placedPair('Charlie');

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('mlm_placement_edges')->insert([
            'id' => (new PlacementEdge)->newUniqueId(),
            'member_id' => $members['Bob']->id,
            'parent_id' => $members['Charlie']->id,
            'placed_at' => now(),
        ]);
    }

    public function test_the_database_refuses_a_duplicate_placement_path(): void
    {
        $members = $this->placedPair();

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('mlm_genealogy_paths')->insert([
            'tree_type' => 'placement',
            'ancestor_id' => $members['Alice']->id,
            'descendant_id' => $members['Bob']->id,
            'depth' => 1,
            'effective_from' => now(),
        ]);
    }

    public function test_a_sponsor_path_and_a_placement_path_for_the_same_pair_coexist(): void
    {
        $members = $this->placedPair();

        $this->genealogy()->assignSponsor($members['Bob'], $members['Alice']);

        $paths = $this->genealogyState()['paths'];
        $this->assertContains('sponsor: Alice > Bob @1', $paths);
        $this->assertContains('placement: Alice > Bob @1', $paths);
    }

    public function test_the_database_refuses_an_edge_to_a_member_that_does_not_exist(): void
    {
        ['Bob' => $bob] = $this->members(Program::factory()->create(), 'Bob');

        $this->expectException(QueryException::class);

        DB::table('mlm_placement_edges')->insert([
            'id' => (new PlacementEdge)->newUniqueId(),
            'member_id' => $bob->id,
            'parent_id' => (new Member)->newUniqueId(),
            'placed_at' => now(),
        ]);
    }

    public function test_a_placed_member_cannot_be_deleted(): void
    {
        $members = $this->placedPair();

        $this->expectException(QueryException::class);

        $members['Bob']->delete();
    }

    public function test_a_placement_parent_cannot_be_deleted(): void
    {
        $members = $this->placedPair();

        $this->expectException(QueryException::class);

        $members['Alice']->delete();
    }

    public function test_a_member_with_placement_paths_cannot_be_deleted(): void
    {
        $members = $this->placedPair();

        // Corruption simulation: without the edge, only Alice's placement
        // paths — her self path among them — still reference her.
        DB::table('mlm_placement_edges')->delete();

        $this->expectException(QueryException::class);

        $members['Alice']->delete();
    }

    public function test_a_member_outside_both_genealogies_can_still_be_deleted(): void
    {
        $members = $this->placedPair();
        $this->genealogy()->assignSponsor($members['Bob'], $members['Alice']);
        $loner = Member::factory()->create();

        $loner->delete();

        $this->assertModelMissing($loner);
    }

    public function test_an_edge_cannot_be_created_through_the_model(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob');

        $this->expectException(InvalidPlacementAssignment::class);

        (new PlacementEdge)->forceFill([
            'member_id' => $members['Bob']->id,
            'parent_id' => $members['Alice']->id,
            'placed_at' => now(),
        ])->save();
    }

    public function test_an_edge_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        new PlacementEdge(['member_id' => 'x', 'parent_id' => 'y']);
    }

    public function test_an_edge_cannot_be_changed_or_deleted_through_the_model(): void
    {
        $members = $this->placedPair('Charlie');
        $edge = PlacementEdge::query()->sole();

        foreach ([
            'update' => fn () => $edge->forceFill(['parent_id' => $members['Charlie']->id])->save(),
            'delete' => fn () => $edge->delete(),
        ] as $operation => $write) {
            try {
                $write();
                $this->fail("A placement edge {$operation} went through the model.");
            } catch (InvalidPlacementAssignment) {
                $this->assertSame(['Alice > Bob'], $this->placementState()['edges']);
            }
        }
    }

    /**
     * Bob placed under Alice; any other codes are members placed nowhere.
     *
     * @return array<string, Member>
     */
    private function placedPair(string ...$others): array
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob', ...$others);
        $this->placementTree($members, ['Alice' => ['Bob']]);

        return $members;
    }
}
