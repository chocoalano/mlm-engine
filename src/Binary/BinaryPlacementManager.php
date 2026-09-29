<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Binary;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use PandaBear\Mlm\Exceptions\CorruptBinaryPlacement;
use PandaBear\Mlm\Exceptions\InvalidBinaryPlacement;
use PandaBear\Mlm\Exceptions\InvalidPlacementAssignment;
use PandaBear\Mlm\Genealogy\ClosureTree;
use PandaBear\Mlm\Genealogy\PlacementGenealogy;
use PandaBear\Mlm\Models\BinaryPlacementPosition;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\PlacementEdge;
use PandaBear\Mlm\Support\EffectiveMoment;

/**
 * Writes the binary overlay (ADR-022): generic placement edges assigned,
 * explicitly, to their parent's left or right — the only way positions and
 * binary paths are written.
 *
 * The generic placement stays what it is: any number of children, no sides.
 * A parent has at most one binary child on each side, and only edges given a
 * side here are in the binary tree — with their own paths, under tree_type
 * 'binary', so a generic-only edge below a binary member never joins a leg.
 *
 * The overlay only grows: a side is assigned once, never changed, and never
 * removed, and nothing is placed automatically. There is one binary tree per
 * program.
 */
final readonly class BinaryPlacementManager
{
    private const EDGES = 'mlm_placement_edges';

    private const POSITIONS = 'mlm_binary_placement_positions';

    private ClosureTree $tree;

    public function __construct(private PlacementGenealogy $placement)
    {
        $this->tree = new ClosureTree('binary');
    }

    /**
     * Places `$member` under `$parent` — through `PlacementGenealogy`, with
     * all its rules — and assigns that new edge to `$side`, both or neither.
     * The side takes effect with the placement, unless the binary tree
     * already holds a later moment for either part it joins.
     *
     * Not a replay: a member already placed is refused as the generic
     * placement refuses it. To give an existing edge a side, adopt it.
     *
     * @throws InvalidPlacementAssignment
     * @throws InvalidBinaryPlacement
     */
    public function place(Member $member, Member $parent, BinarySide $side): BinaryPlacementPosition
    {
        $connection = $member->getConnection();

        // One transaction: a side that cannot be taken undoes the placement
        // made for it.
        return $connection->transaction(function () use ($member, $parent, $side, $connection): BinaryPlacementPosition {
            $edge = $this->placement->place($member, $parent);

            return $this->assign($connection, (string) $edge->getKey(), $side, adopted: false);
        });
    }

    /**
     * Assigns an existing generic placement edge to `$side`, from now on:
     * however long ago it was placed, the binary relationship begins with its
     * adoption, so earlier activity never becomes binary activity.
     *
     * Adopting an edge again on the same side returns its position; on the
     * other side it is refused — a side never moves.
     *
     * @throws InvalidBinaryPlacement
     * @throws CorruptBinaryPlacement
     */
    public function adopt(PlacementEdge $placement, BinarySide $side): BinaryPlacementPosition
    {
        $connection = $placement->getConnection();

        return $connection->transaction(
            fn (): BinaryPlacementPosition => $this->assign($connection, (string) $placement->getKey(), $side, adopted: true),
        );
    }

    /**
     * Decided from the database, never from the instances passed in: the
     * edge and both its members are re-read under lock, then the program is
     * locked — the lock every genealogy write takes, so writes to one
     * program's trees run one at a time and each check holds until commit.
     */
    private function assign(Connection $connection, string $edgeId, BinarySide $side, bool $adopted): BinaryPlacementPosition
    {
        $edge = PlacementEdge::on($connection->getName())->whereKey($edgeId)->sharedLock()->first()
            ?? throw InvalidBinaryPlacement::missingEdge($edgeId);

        [$member, $parent] = $this->tree->lockMembers($this->member($connection, $edge->member_id), $this->member($connection, $edge->parent_id));

        if ($member->program_id !== $parent->program_id) {
            throw CorruptBinaryPlacement::crossProgram($edgeId, $member->getKey(), $parent->getKey());
        }

        $this->tree->lockProgram($member);

        // Locking reads: they see the latest committed positions whatever the
        // caller's transaction read before.
        $existing = BinaryPlacementPosition::on($connection->getName())->where('placement_edge_id', $edgeId)->lockForUpdate()->first();

        if ($existing !== null) {
            if ($existing->parent_id !== $edge->parent_id) {
                throw CorruptBinaryPlacement::parentMismatch($existing->getKey(), $existing->parent_id, $edgeId, $edge->parent_id);
            }

            if ($existing->side !== $side) {
                throw InvalidBinaryPlacement::alreadyOnOtherSide($edgeId, $existing->side, $side);
            }

            return $existing;
        }

        $occupant = $connection->table(self::POSITIONS)
            ->where('parent_id', $parent->getKey())
            ->where('side', $side->value)
            ->lockForUpdate()
            ->value('placement_edge_id');

        if (is_string($occupant)) {
            throw InvalidBinaryPlacement::sideOccupied($parent, $side, (string) $connection->table(self::EDGES)->where('id', $occupant)->value('member_id'));
        }

        // Binary edges are placement edges, so this holds while the paths
        // match the positions; it guards the tree against rows that do not.
        if ($this->tree->hasPath($connection, $member->getKey(), $parent->getKey())) {
            throw CorruptBinaryPlacement::cycle($edgeId);
        }

        // Never before the generic placement. A placement made now takes its
        // side from its own moment; an adopted one from now.
        $placed = EffectiveMoment::of($edge->placed_at);
        $position = new BinaryPlacementPosition;
        $now = $adopted ? EffectiveMoment::of($position->freshTimestamp()) : $placed;

        // One moment for the whole assignment, floored as every genealogy
        // write is by the moments already in the two parts it joins.
        $at = $this->tree->joinMoment($connection, $parent->getKey(), $member->getKey(), $this->latest($now, $placed));
        $id = $position->newUniqueId();

        $this->tree->ensureSelfPaths($connection, [$member->getKey(), $parent->getKey()], $at);

        $connection->table(self::POSITIONS)->insert([
            'id' => $id,
            'placement_edge_id' => $edgeId,
            'parent_id' => $parent->getKey(),
            'side' => $side->value,
            'assigned_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        $this->tree->attach($connection, $parent->getKey(), $member->getKey(), $at);

        return BinaryPlacementPosition::on($connection->getName())->findOrFail($id);
    }

    /**
     * An unread member, by key, for the locking read that follows.
     */
    private function member(Connection $connection, string $id): Member
    {
        return (new Member)->setConnection($connection->getName())->forceFill(['id' => $id]);
    }

    private function latest(CarbonImmutable $a, CarbonImmutable $b): CarbonImmutable
    {
        return $a->greaterThan($b) ? $a : $b;
    }
}
