<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Matrix;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use PandaBear\Mlm\Exceptions\CorruptMatrixPlacement;
use PandaBear\Mlm\Exceptions\InvalidMatrixPlacement;
use PandaBear\Mlm\Genealogy\ClosureTree;
use PandaBear\Mlm\Models\MatrixNetwork;
use PandaBear\Mlm\Models\MatrixPlacementPosition;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\PlacementEdge;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Support\EffectiveMoment;

/**
 * The matrix of one program, read-only (ADR-027): which member is in which
 * numbered slot of whom, and the matrix lines and subtrees those positions
 * imply.
 *
 * Only edges given a slot belong to it. A member with a generic placement
 * parent but no matrix position is a matrix root, and a generic-only edge
 * below a matrix member is not in any matrix subtree. Neither sponsorship,
 * the generic placement paths nor the binary paths are read. The matrix has
 * no structural depth: how deep a metric or a strategy reads is its own
 * setting.
 *
 * Positions and paths only grow, and remember when: the queries without a
 * moment read the matrix as it stands; the `…At()` queries read it as it
 * stood at a moment, so an edge adopted later is absent before its
 * adoption. Every query starts from the stored member, whatever the
 * instance passed in claims, and a stored position that disagrees with its
 * placement edge, its program's network or its width is refused, not read.
 */
final class MatrixGenealogy
{
    private const EDGES = 'mlm_placement_edges';

    private readonly ClosureTree $tree;

    public function __construct()
    {
        $this->tree = new ClosureTree('matrix');
    }

    /**
     * The program's matrix network — of the member's program, for a member
     * — or null while none is configured.
     */
    public function network(Member|Program $of): ?MatrixNetwork
    {
        $program = $of instanceof Member ? $this->stored($of)->program_id : $of->newQuery()->findOrFail($of->getKey())->getKey();

        return MatrixNetwork::on($of->getConnection()->getName())->where('program_id', $program)->first();
    }

    /**
     * The member's own matrix position — its parent and slot — or null for
     * a matrix root.
     *
     * @throws CorruptMatrixPlacement
     */
    public function positionOf(Member $member): ?MatrixPlacementPosition
    {
        return $this->incoming($member, null);
    }

    /**
     * The member's matrix position at `$at`: null while it had none yet.
     *
     * @throws CorruptMatrixPlacement
     */
    public function positionOfAt(Member $member, DateTimeInterface $at): ?MatrixPlacementPosition
    {
        return $this->incoming($member, EffectiveMoment::of($at));
    }

    /**
     * @throws CorruptMatrixPlacement
     */
    public function directParent(Member $member): ?Member
    {
        return $this->incoming($member, null)?->parent;
    }

    /**
     * @throws CorruptMatrixPlacement
     */
    public function directParentAt(Member $member, DateTimeInterface $at): ?Member
    {
        return $this->incoming($member, EffectiveMoment::of($at))?->parent;
    }

    /**
     * The matrix child in `$slot` of `$parent`, or null while that slot is
     * free. A slot is what was assigned, never the order children were
     * placed in.
     *
     * @param  int  $slot
     *
     * @throws InvalidMatrixPlacement for a slot that is not a whole number of 1 or more
     * @throws CorruptMatrixPlacement
     */
    public function child(Member $parent, mixed $slot): ?Member
    {
        return $this->outgoing($parent, $slot, null)?->member;
    }

    /**
     * @param  int  $slot
     *
     * @throws InvalidMatrixPlacement
     * @throws CorruptMatrixPlacement
     */
    public function childAt(Member $parent, mixed $slot, DateTimeInterface $at): ?Member
    {
        return $this->outgoing($parent, $slot, EffectiveMoment::of($at))?->member;
    }

    /**
     * The member's matrix parent, its parent, and so on — nearest first.
     * The member itself is not included. `$maxDepth` 1 is the matrix parent
     * only.
     *
     * @return Collection<int, MatrixRelative>
     */
    public function ancestors(Member $member, ?int $maxDepth = null): Collection
    {
        return $this->relatives($this->tree->ancestors($this->stored($member), $maxDepth));
    }

    /**
     * @return Collection<int, MatrixRelative>
     */
    public function ancestorsAt(Member $member, DateTimeInterface $at, ?int $maxDepth = null): Collection
    {
        return $this->relatives($this->tree->ancestors($this->stored($member), $maxDepth, EffectiveMoment::of($at)));
    }

    /**
     * Everyone below the member in the matrix, in every slot — nearest
     * first, then by member key. The member itself is not included.
     *
     * @return Collection<int, MatrixRelative>
     */
    public function descendants(Member $member, ?int $maxDepth = null): Collection
    {
        return $this->relatives($this->tree->descendants($this->stored($member), $maxDepth));
    }

    /**
     * @return Collection<int, MatrixRelative>
     */
    public function descendantsAt(Member $member, DateTimeInterface $at, ?int $maxDepth = null): Collection
    {
        return $this->relatives($this->tree->descendants($this->stored($member), $maxDepth, EffectiveMoment::of($at)));
    }

    private function incoming(Member $member, ?CarbonImmutable $at): ?MatrixPlacementPosition
    {
        $member = $this->stored($member);

        $position = $this->positions($member, $at)
            ->whereIn('placement_edge_id', $member->getConnection()->table(self::EDGES)->select('id')->where('member_id', $member->getKey()))
            ->first();

        return $position === null ? null : $this->verified($position, $member);
    }

    private function outgoing(Member $parent, mixed $slot, ?CarbonImmutable $at): ?MatrixPlacementPosition
    {
        if (! is_int($slot) || $slot < 1) {
            throw InvalidMatrixPlacement::slot($slot);
        }

        $parent = $this->stored($parent);

        $position = $this->positions($parent, $at)
            ->where('parent_id', $parent->getKey())
            ->where('slot', $slot)
            ->first();

        return $position === null ? null : $this->verified($position, $parent);
    }

    /**
     * @return Builder<MatrixPlacementPosition>
     */
    private function positions(Member $member, ?CarbonImmutable $at): Builder
    {
        return MatrixPlacementPosition::on($member->getConnection()->getName())
            ->when($at !== null, static fn (Builder $query): Builder => $query->where('assigned_at', '<=', $at));
    }

    /**
     * The position with its network, edge, parent and member, once they
     * agree: its program's network, a slot within the width, the edge's
     * parent, and both members in the program of the member the read
     * started from.
     */
    private function verified(MatrixPlacementPosition $position, Member $from): MatrixPlacementPosition
    {
        $connection = $from->getConnection()->getName();
        $network = MatrixNetwork::on($connection)->where('program_id', $from->program_id)->first();

        if ($network === null || $position->matrix_network_id !== $network->getKey()) {
            throw CorruptMatrixPlacement::otherNetwork($position->getKey(), $position->matrix_network_id, (string) $network?->getKey());
        }

        if ($position->slot < 1 || $position->slot > $network->width) {
            throw CorruptMatrixPlacement::slot($position->getKey(), $position->slot, $network->width);
        }

        $edge = PlacementEdge::on($connection)->findOrFail($position->placement_edge_id);

        if ($edge->parent_id !== $position->parent_id) {
            throw CorruptMatrixPlacement::parentMismatch($position->getKey(), $position->parent_id, $edge->getKey(), $edge->parent_id);
        }

        $members = Member::on($connection)->whereKey([$edge->member_id, $edge->parent_id])->get()->keyBy('id');
        $member = $members->get($edge->member_id);
        $parent = $members->get($edge->parent_id);

        if ($member?->program_id !== $from->program_id || $parent?->program_id !== $from->program_id) {
            throw CorruptMatrixPlacement::crossProgram($edge->getKey(), $edge->member_id, $edge->parent_id);
        }

        return $position
            ->setRelation('network', $network)
            ->setRelation('placementEdge', $edge)
            ->setRelation('parent', $parent)
            ->setRelation('member', $member);
    }

    private function stored(Member $member): Member
    {
        return $member->newQuery()->findOrFail($member->getKey());
    }

    /**
     * @param  Collection<int, array{Member, int}>  $relatives
     * @return Collection<int, MatrixRelative>
     */
    private function relatives(Collection $relatives): Collection
    {
        return $relatives->map(static fn (array $relative): MatrixRelative => new MatrixRelative(...$relative));
    }
}
