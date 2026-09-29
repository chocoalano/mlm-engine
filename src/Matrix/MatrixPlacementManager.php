<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Matrix;

use Illuminate\Database\Connection;
use PandaBear\Mlm\Exceptions\CorruptMatrixPlacement;
use PandaBear\Mlm\Exceptions\InvalidMatrixPlacement;
use PandaBear\Mlm\Exceptions\InvalidPlacementAssignment;
use PandaBear\Mlm\Genealogy\ClosureTree;
use PandaBear\Mlm\Genealogy\PlacementGenealogy;
use PandaBear\Mlm\Models\MatrixNetwork;
use PandaBear\Mlm\Models\MatrixPlacementPosition;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\PlacementEdge;
use PandaBear\Mlm\Support\EffectiveMoment;

/**
 * Writes the matrix overlay (ADR-027): generic placement edges assigned,
 * explicitly, to one of their parent's numbered slots — the only way matrix
 * positions and matrix paths are written.
 *
 * The generic placement stays what it is: any number of children, no slots,
 * no width. A parent has at most one matrix child per slot, its slots
 * running 1 to its program's matrix width, and only edges given a slot here
 * are in the matrix — with their own paths, under tree_type 'matrix', so a
 * generic-only edge below a matrix member never joins the matrix.
 *
 * The overlay only grows: a slot is assigned once, never changed, and never
 * removed, and nothing is placed automatically — the caller always chooses
 * the parent and the slot. There is no spillover and no free-slot search.
 * Sponsorship is neither read nor written.
 */
final readonly class MatrixPlacementManager
{
    private const EDGES = 'mlm_placement_edges';

    private const POSITIONS = 'mlm_matrix_placement_positions';

    private ClosureTree $tree;

    public function __construct(private PlacementGenealogy $placement)
    {
        $this->tree = new ClosureTree('matrix');
    }

    /**
     * Places `$member` under `$parent` — through `PlacementGenealogy`, with
     * all its rules — and assigns that new edge to `$slot`, both or neither.
     * The slot takes effect with the placement, unless the matrix already
     * holds a later moment for either part it joins.
     *
     * Not a replay: a member already placed is refused as the generic
     * placement refuses it. To give an existing edge a slot, adopt it.
     *
     * @param  int  $slot
     *
     * @throws InvalidPlacementAssignment
     * @throws InvalidMatrixPlacement
     */
    public function place(Member $member, Member $parent, mixed $slot): MatrixPlacementPosition
    {
        $slot = self::slot($slot);
        $connection = $member->getConnection();

        // One transaction: a slot that cannot be taken undoes the placement
        // made for it.
        return $connection->transaction(function () use ($member, $parent, $slot, $connection): MatrixPlacementPosition {
            $edge = $this->placement->place($member, $parent);

            return $this->assign($connection, (string) $edge->getKey(), $slot, adopted: false);
        });
    }

    /**
     * Assigns an existing generic placement edge to `$slot`, from now on:
     * however long ago it was placed, the matrix relationship begins with
     * its adoption, so earlier activity never becomes matrix activity. The
     * member's own matrix subtree, if it has one, comes with it.
     *
     * Adopting an edge again into the same slot returns its position; into
     * another slot it is refused — a slot never moves.
     *
     * @param  int  $slot
     *
     * @throws InvalidMatrixPlacement
     * @throws CorruptMatrixPlacement
     */
    public function adopt(PlacementEdge $placement, mixed $slot): MatrixPlacementPosition
    {
        $slot = self::slot($slot);
        $connection = $placement->getConnection();

        return $connection->transaction(
            fn (): MatrixPlacementPosition => $this->assign($connection, (string) $placement->getKey(), $slot, adopted: true),
        );
    }

    /**
     * Decided from the database, never from the instances passed in: the
     * edge and both its members are re-read under lock, then the program is
     * locked — the lock every genealogy write, and the network's
     * configuration, takes — so writes to one program's trees run one at a
     * time and each check holds until commit. Every read before the checks
     * is a locking read, so it sees what the writes before it committed.
     */
    private function assign(Connection $connection, string $edgeId, int $slot, bool $adopted): MatrixPlacementPosition
    {
        $edge = PlacementEdge::on($connection->getName())->whereKey($edgeId)->sharedLock()->first()
            ?? throw InvalidMatrixPlacement::missingEdge($edgeId);

        [$member, $parent] = $this->tree->lockMembers($this->member($connection, $edge->member_id), $this->member($connection, $edge->parent_id));

        if ($member->program_id !== $parent->program_id) {
            throw CorruptMatrixPlacement::crossProgram($edgeId, $member->getKey(), $parent->getKey());
        }

        $this->tree->lockProgram($member);

        $network = MatrixNetwork::on($connection->getName())->where('program_id', $member->program_id)->sharedLock()->first()
            ?? throw InvalidMatrixPlacement::noNetwork($member->program_id);

        if ($slot > $network->width) {
            throw InvalidMatrixPlacement::slotBeyondWidth($slot, $network->width);
        }

        $existing = MatrixPlacementPosition::on($connection->getName())->where('placement_edge_id', $edgeId)->lockForUpdate()->first();

        if ($existing !== null) {
            if ($existing->matrix_network_id !== $network->getKey()) {
                throw CorruptMatrixPlacement::otherNetwork($existing->getKey(), $existing->matrix_network_id, $network->getKey());
            }

            if ($existing->parent_id !== $edge->parent_id) {
                throw CorruptMatrixPlacement::parentMismatch($existing->getKey(), $existing->parent_id, $edgeId, $edge->parent_id);
            }

            if ($existing->slot !== $slot) {
                throw InvalidMatrixPlacement::alreadyInOtherSlot($edgeId, $existing->slot, $slot);
            }

            return $existing;
        }

        $occupant = $connection->table(self::POSITIONS)
            ->where('matrix_network_id', $network->getKey())
            ->where('parent_id', $parent->getKey())
            ->where('slot', $slot)
            ->lockForUpdate()
            ->value('placement_edge_id');

        if (is_string($occupant)) {
            throw InvalidMatrixPlacement::slotOccupied($parent, $slot, (string) $connection->table(self::EDGES)->where('id', $occupant)->value('member_id'));
        }

        // Matrix edges are placement edges, so this holds while the paths
        // match the positions; it guards the tree against rows that do not.
        if ($this->tree->hasPath($connection, $member->getKey(), $parent->getKey())) {
            throw CorruptMatrixPlacement::cycle($edgeId);
        }

        // Never before the generic placement. A placement made now takes its
        // slot from its own moment; an adopted one from now.
        $placed = EffectiveMoment::of($edge->placed_at);
        $position = new MatrixPlacementPosition;
        $now = $adopted ? EffectiveMoment::of($position->freshTimestamp()) : $placed;

        // One moment for the whole assignment, floored as every genealogy
        // write is by the moments already in the two parts it joins.
        $at = $this->tree->joinMoment($connection, $parent->getKey(), $member->getKey(), $now->greaterThan($placed) ? $now : $placed);
        $id = $position->newUniqueId();

        $this->tree->ensureSelfPaths($connection, [$member->getKey(), $parent->getKey()], $at);

        $connection->table(self::POSITIONS)->insert([
            'id' => $id,
            'matrix_network_id' => $network->getKey(),
            'placement_edge_id' => $edgeId,
            'parent_id' => $parent->getKey(),
            'slot' => $slot,
            'assigned_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        $this->tree->attach($connection, $parent->getKey(), $member->getKey(), $at);

        return MatrixPlacementPosition::on($connection->getName())->findOrFail($id);
    }

    /**
     * A slot as asked for: a PHP integer of 1 or more. Its upper bound is
     * the network's width, checked once the network is read.
     */
    private static function slot(mixed $slot): int
    {
        if (! is_int($slot) || $slot < 1) {
            throw InvalidMatrixPlacement::slot($slot);
        }

        return $slot;
    }

    /**
     * An unread member, by key, for the locking read that follows.
     */
    private function member(Connection $connection, string $id): Member
    {
        return (new Member)->setConnection($connection->getName())->forceFill(['id' => $id]);
    }
}
