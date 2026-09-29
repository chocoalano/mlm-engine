<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Binary;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use PandaBear\Mlm\Exceptions\CorruptBinaryPlacement;
use PandaBear\Mlm\Genealogy\ClosureTree;
use PandaBear\Mlm\Models\BinaryPlacementPosition;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\PlacementEdge;
use PandaBear\Mlm\Support\EffectiveMoment;

/**
 * The binary tree of one program, read-only (ADR-022): which member is on
 * which side of whom, and the binary lines and subtrees those positions
 * imply.
 *
 * Only edges given a side belong to it. A member with a generic placement
 * parent but no binary position is a binary root, and a generic-only edge
 * below a binary member is not in any binary subtree. Neither sponsorship
 * nor the generic placement paths are read.
 *
 * Positions and paths only grow, and remember when: the queries without a
 * moment read the tree as it stands; the `…At()` queries read it as it
 * stood at a moment, so an edge adopted later is absent before its
 * adoption. Every query starts from the stored member, whatever the
 * instance passed in claims, and a stored position that disagrees with its
 * placement edge is refused, not read.
 */
final class BinaryGenealogy
{
    private const EDGES = 'mlm_placement_edges';

    private readonly ClosureTree $tree;

    public function __construct()
    {
        $this->tree = new ClosureTree('binary');
    }

    /**
     * The member's own binary position — its parent and side — or null for
     * a binary root.
     *
     * @throws CorruptBinaryPlacement
     */
    public function positionOf(Member $member): ?BinaryPlacementPosition
    {
        return $this->incoming($member, null);
    }

    /**
     * The member's binary position at `$at`: null while it had none yet.
     *
     * @throws CorruptBinaryPlacement
     */
    public function positionOfAt(Member $member, DateTimeInterface $at): ?BinaryPlacementPosition
    {
        return $this->incoming($member, EffectiveMoment::of($at));
    }

    /**
     * @throws CorruptBinaryPlacement
     */
    public function directParent(Member $member): ?Member
    {
        return $this->incoming($member, null)?->parent;
    }

    /**
     * @throws CorruptBinaryPlacement
     */
    public function directParentAt(Member $member, DateTimeInterface $at): ?Member
    {
        return $this->incoming($member, EffectiveMoment::of($at))?->parent;
    }

    /**
     * The position on `$side` of `$parent`, or null while that side is free.
     * Its placed member is its `member`.
     *
     * @throws CorruptBinaryPlacement
     */
    public function positionUnder(Member $parent, BinarySide $side): ?BinaryPlacementPosition
    {
        return $this->outgoing($parent, $side, null);
    }

    /**
     * @throws CorruptBinaryPlacement
     */
    public function positionUnderAt(Member $parent, BinarySide $side, DateTimeInterface $at): ?BinaryPlacementPosition
    {
        return $this->outgoing($parent, $side, EffectiveMoment::of($at));
    }

    /**
     * The binary child on `$side` of `$parent`, or null. A side is what was
     * assigned, never the order children were placed in.
     *
     * @throws CorruptBinaryPlacement
     */
    public function child(Member $parent, BinarySide $side): ?Member
    {
        return $this->outgoing($parent, $side, null)?->member;
    }

    /**
     * @throws CorruptBinaryPlacement
     */
    public function childAt(Member $parent, BinarySide $side, DateTimeInterface $at): ?Member
    {
        return $this->outgoing($parent, $side, EffectiveMoment::of($at))?->member;
    }

    /**
     * The member's binary parent, its parent, and so on — nearest first. The
     * member itself is not included. `$maxDepth` 1 is the binary parent only.
     *
     * @return Collection<int, BinaryRelative>
     */
    public function ancestors(Member $member, ?int $maxDepth = null): Collection
    {
        return $this->relatives($this->tree->ancestors($this->stored($member), $maxDepth));
    }

    /**
     * @return Collection<int, BinaryRelative>
     */
    public function ancestorsAt(Member $member, DateTimeInterface $at, ?int $maxDepth = null): Collection
    {
        return $this->relatives($this->tree->ancestors($this->stored($member), $maxDepth, EffectiveMoment::of($at)));
    }

    /**
     * Everyone below the member in the binary tree, on either side — nearest
     * first, then by member key. The member itself is not included.
     *
     * @return Collection<int, BinaryRelative>
     */
    public function descendants(Member $member, ?int $maxDepth = null): Collection
    {
        return $this->relatives($this->tree->descendants($this->stored($member), $maxDepth));
    }

    /**
     * @return Collection<int, BinaryRelative>
     */
    public function descendantsAt(Member $member, DateTimeInterface $at, ?int $maxDepth = null): Collection
    {
        return $this->relatives($this->tree->descendants($this->stored($member), $maxDepth, EffectiveMoment::of($at)));
    }

    private function incoming(Member $member, ?CarbonImmutable $at): ?BinaryPlacementPosition
    {
        $member = $this->stored($member);

        $position = $this->positions($member, $at)
            ->whereIn('placement_edge_id', $member->getConnection()->table(self::EDGES)->select('id')->where('member_id', $member->getKey()))
            ->first();

        return $position === null ? null : $this->verified($position, $member);
    }

    private function outgoing(Member $parent, BinarySide $side, ?CarbonImmutable $at): ?BinaryPlacementPosition
    {
        $parent = $this->stored($parent);

        $position = $this->positions($parent, $at)
            ->where('parent_id', $parent->getKey())
            ->where('side', $side->value)
            ->first();

        return $position === null ? null : $this->verified($position, $parent);
    }

    /**
     * @return Builder<BinaryPlacementPosition>
     */
    private function positions(Member $member, ?CarbonImmutable $at): Builder
    {
        return BinaryPlacementPosition::on($member->getConnection()->getName())
            ->when($at !== null, static fn (Builder $query): Builder => $query->where('assigned_at', '<=', $at));
    }

    /**
     * The position with its edge, parent and member, once they agree: the
     * edge's parent, a side spelled exactly, and both members in the
     * program of the member the read started from.
     */
    private function verified(BinaryPlacementPosition $position, Member $from): BinaryPlacementPosition
    {
        $connection = $from->getConnection()->getName();
        $edge = PlacementEdge::on($connection)->findOrFail($position->placement_edge_id);

        if ($edge->parent_id !== $position->parent_id) {
            throw CorruptBinaryPlacement::parentMismatch($position->getKey(), $position->parent_id, $edge->getKey(), $edge->parent_id);
        }

        if (BinarySide::parse($position->getAttributes()['side'] ?? null) === null) {
            throw CorruptBinaryPlacement::side($position->getKey(), $position->getAttributes()['side'] ?? null);
        }

        $members = Member::on($connection)->whereKey([$edge->member_id, $edge->parent_id])->get()->keyBy('id');
        $member = $members->get($edge->member_id);
        $parent = $members->get($edge->parent_id);

        if ($member?->program_id !== $from->program_id || $parent?->program_id !== $from->program_id) {
            throw CorruptBinaryPlacement::crossProgram($edge->getKey(), $edge->member_id, $edge->parent_id);
        }

        return $position->setRelation('placementEdge', $edge)->setRelation('parent', $parent)->setRelation('member', $member);
    }

    private function stored(Member $member): Member
    {
        return $member->newQuery()->findOrFail($member->getKey());
    }

    /**
     * @param  Collection<int, array{Member, int}>  $relatives
     * @return Collection<int, BinaryRelative>
     */
    private function relatives(Collection $relatives): Collection
    {
        return $relatives->map(static fn (array $relative): BinaryRelative => new BinaryRelative(...$relative));
    }
}
