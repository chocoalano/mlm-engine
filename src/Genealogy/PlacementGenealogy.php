<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Genealogy;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use PandaBear\Mlm\Exceptions\InvalidPlacementAssignment;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\PlacementEdge;

/**
 * Where each member is structurally placed, within one program.
 *
 * Independent of sponsorship: a member may be placed under someone other
 * than its sponsor, placed without a sponsor, or sponsored without being
 * placed. Neither genealogy reads or writes the other.
 *
 * A member has at most one placement parent, assigned once; a parent may
 * have any number of members placed under it — capacity, positions and
 * automatic placement belong to later plan-specific strategies. Members
 * without a placement parent are roots, and a program may have many.
 *
 * Direct placements are edges. Every ancestor/descendant pair they imply is
 * kept in the shared closure table under tree_type 'placement'.
 */
final class PlacementGenealogy
{
    private const EDGES = 'mlm_placement_edges';

    private readonly ClosureTree $tree;

    public function __construct()
    {
        $this->tree = new ClosureTree('placement');
    }

    /**
     * Places `$member` directly under `$parent`. Once: a placement is never
     * moved or removed.
     *
     * Decided from the database, never from the instances passed in: both
     * members are re-read under lock inside the transaction.
     *
     * @throws InvalidPlacementAssignment
     */
    public function place(Member $member, Member $parent): PlacementEdge
    {
        if ($member->getKey() === $parent->getKey()) {
            throw InvalidPlacementAssignment::selfPlacement($member);
        }

        $connection = $member->getConnection();

        return $connection->transaction(function () use ($member, $parent, $connection): PlacementEdge {
            [$member, $parent] = $this->tree->lockMembers($member, $parent);

            if ($member->program_id !== $parent->program_id) {
                throw InvalidPlacementAssignment::differentPrograms($member, $parent);
            }

            $this->tree->lockProgram($member);

            $current = $connection->table(self::EDGES)->where('member_id', $member->getKey())->value('parent_id');

            if (is_string($current)) {
                throw InvalidPlacementAssignment::alreadyPlaced($member, $current, $parent);
            }

            // The parent must not already be in the member's placement subtree.
            if ($this->tree->hasPath($connection, $member->getKey(), $parent->getKey())) {
                throw InvalidPlacementAssignment::cycle($member, $parent);
            }

            $this->tree->ensureSelfPaths($connection, [$member->getKey(), $parent->getKey()]);

            $edge = new PlacementEdge;
            $id = $edge->newUniqueId();
            $now = $edge->freshTimestamp();

            $connection->table(self::EDGES)->insert([
                'id' => $id,
                'member_id' => $member->getKey(),
                'parent_id' => $parent->getKey(),
                'placed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->tree->attach($connection, $parent->getKey(), $member->getKey());

            return PlacementEdge::on($connection->getName())->findOrFail($id);
        });
    }

    /**
     * The member's placement parent, or null for a placement root. Never
     * reads sponsorship.
     */
    public function directParent(Member $member): ?Member
    {
        return $member->newQuery()
            ->where('program_id', $member->program_id)
            ->whereIn(
                $member->getKeyName(),
                $member->getConnection()->table(self::EDGES)->select('parent_id')->where('member_id', $member->getKey()),
            )
            ->first();
    }

    /**
     * The members placed directly under `$parent`, in the order they were
     * placed. The order is chronological only; it is not a slot or position.
     *
     * @return EloquentCollection<int, Member>
     */
    public function directChildren(Member $parent): EloquentCollection
    {
        return $parent->newQuery()
            ->select($parent->qualifyColumn('*'))
            ->join(self::EDGES, self::EDGES.'.member_id', '=', $parent->getQualifiedKeyName())
            ->where(self::EDGES.'.parent_id', $parent->getKey())
            ->where($parent->qualifyColumn('program_id'), $parent->program_id)
            ->orderBy(self::EDGES.'.placed_at')
            ->orderBy($parent->getQualifiedKeyName())
            ->get();
    }

    /**
     * The member's placement parent, its parent, and so on — nearest first.
     * The member itself is not included. `$maxDepth` 1 is the direct parent
     * only.
     *
     * @return Collection<int, PlacementRelative>
     */
    public function ancestors(Member $member, ?int $maxDepth = null): Collection
    {
        return $this->tree->ancestors($member, $maxDepth)
            ->map(static fn (array $relative): PlacementRelative => new PlacementRelative(...$relative));
    }

    /**
     * Everyone placed under the member, directly or further down — nearest
     * first, then in the order the members were created. The member itself is
     * not included. `$maxDepth` 1 is the direct children only.
     *
     * @return Collection<int, PlacementRelative>
     */
    public function descendants(Member $member, ?int $maxDepth = null): Collection
    {
        return $this->tree->descendants($member, $maxDepth)
            ->map(static fn (array $relative): PlacementRelative => new PlacementRelative(...$relative));
    }
}
