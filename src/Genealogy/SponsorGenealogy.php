<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Genealogy;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use PandaBear\Mlm\Exceptions\InvalidSponsorAssignment;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\SponsorEdge;
use PandaBear\Mlm\Support\EffectiveMoment;

/**
 * Who sponsored whom, within one program.
 *
 * A member has at most one direct sponsor, assigned once; a sponsor may
 * sponsor any number of members; members without a sponsor are roots, and a
 * program may have many. The sponsor tree is never a placement structure.
 *
 * Direct sponsorships are edges. Every ancestor/descendant pair they imply is
 * kept in a closure table beside them, so ancestry is read, never walked.
 *
 * The tree only grows, and remembers when: each edge its `assigned_at`, each
 * path the moment it took effect. The queries without a moment read the tree
 * as it stands; the `…At()` queries read it as it stood at a moment.
 */
final class SponsorGenealogy
{
    private const EDGES = 'mlm_sponsor_edges';

    private readonly ClosureTree $tree;

    public function __construct()
    {
        $this->tree = new ClosureTree('sponsor');
    }

    /**
     * Records that `$sponsor` directly sponsored `$member`. Once: an assigned
     * sponsor is never replaced.
     *
     * Decided from the database, never from the instances passed in: both
     * members are re-read under lock inside the transaction.
     *
     * @throws InvalidSponsorAssignment
     */
    public function assignSponsor(Member $member, Member $sponsor): SponsorEdge
    {
        if ($member->getKey() === $sponsor->getKey()) {
            throw InvalidSponsorAssignment::selfSponsorship($member);
        }

        $connection = $member->getConnection();

        return $connection->transaction(function () use ($member, $sponsor, $connection): SponsorEdge {
            [$member, $sponsor] = $this->tree->lockMembers($member, $sponsor);

            if ($member->program_id !== $sponsor->program_id) {
                throw InvalidSponsorAssignment::differentPrograms($member, $sponsor);
            }

            $this->tree->lockProgram($member);

            $current = $connection->table(self::EDGES)->where('member_id', $member->getKey())->value('sponsor_id');

            if (is_string($current)) {
                throw InvalidSponsorAssignment::alreadySponsored($member, $current, $sponsor);
            }

            // The sponsor must not already be in the member's subtree.
            if ($this->tree->hasPath($connection, $member->getKey(), $sponsor->getKey())) {
                throw InvalidSponsorAssignment::cycle($member, $sponsor);
            }

            // One moment for the whole assignment: the edge, any self path it
            // needs and every path it creates take effect together — now,
            // floored by the moments already in the two parts it joins.
            $edge = new SponsorEdge;
            $id = $edge->newUniqueId();
            $at = $this->tree->joinMoment($connection, $sponsor->getKey(), $member->getKey(), EffectiveMoment::of($edge->freshTimestamp()));

            $this->tree->ensureSelfPaths($connection, [$member->getKey(), $sponsor->getKey()], $at);

            $connection->table(self::EDGES)->insert([
                'id' => $id,
                'member_id' => $member->getKey(),
                'sponsor_id' => $sponsor->getKey(),
                'assigned_at' => $at,
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            $this->tree->attach($connection, $sponsor->getKey(), $member->getKey(), $at);

            return SponsorEdge::on($connection->getName())->findOrFail($id);
        });
    }

    public function directSponsor(Member $member): ?Member
    {
        return $this->sponsorOf($member, null);
    }

    /**
     * The member's direct sponsor at `$at`: null while it had none yet, even
     * if it has one now.
     */
    public function directSponsorAt(Member $member, DateTimeInterface $at): ?Member
    {
        return $this->sponsorOf($member, EffectiveMoment::of($at));
    }

    /**
     * The members `$sponsor` sponsored directly, in the order they were
     * sponsored.
     *
     * @return EloquentCollection<int, Member>
     */
    public function directMembers(Member $sponsor): EloquentCollection
    {
        return $this->sponsoredBy($sponsor, null);
    }

    /**
     * The members `$sponsor` had sponsored directly by `$at`, in the order
     * they were sponsored.
     *
     * @return EloquentCollection<int, Member>
     */
    public function directMembersAt(Member $sponsor, DateTimeInterface $at): EloquentCollection
    {
        return $this->sponsoredBy($sponsor, EffectiveMoment::of($at));
    }

    /**
     * The member's sponsor, their sponsor, and so on — nearest first. The
     * member itself is not included. `$maxDepth` 1 is the direct sponsor only.
     *
     * @return Collection<int, SponsorRelative>
     */
    public function ancestors(Member $member, ?int $maxDepth = null): Collection
    {
        return $this->tree->ancestors($member, $maxDepth)
            ->map(static fn (array $relative): SponsorRelative => new SponsorRelative(...$relative));
    }

    /**
     * The member's sponsor line as it stood at `$at`: only the sponsorships
     * already in effect then, so a line completed later is cut where it was
     * still open.
     *
     * @return Collection<int, SponsorRelative>
     */
    public function ancestorsAt(Member $member, DateTimeInterface $at, ?int $maxDepth = null): Collection
    {
        return $this->tree->ancestors($member, $maxDepth, EffectiveMoment::of($at))
            ->map(static fn (array $relative): SponsorRelative => new SponsorRelative(...$relative));
    }

    /**
     * Everyone the member sponsored, directly or further down — nearest
     * first, then in the order the members were created. The member itself is
     * not included. `$maxDepth` 1 is the directly sponsored members only.
     *
     * @return Collection<int, SponsorRelative>
     */
    public function descendants(Member $member, ?int $maxDepth = null): Collection
    {
        return $this->tree->descendants($member, $maxDepth)
            ->map(static fn (array $relative): SponsorRelative => new SponsorRelative(...$relative));
    }

    /**
     * Everyone below the member in the sponsor tree as it stood at `$at`: a
     * member sponsored later, or a subtree attached later, is not included.
     *
     * @return Collection<int, SponsorRelative>
     */
    public function descendantsAt(Member $member, DateTimeInterface $at, ?int $maxDepth = null): Collection
    {
        return $this->tree->descendants($member, $maxDepth, EffectiveMoment::of($at))
            ->map(static fn (array $relative): SponsorRelative => new SponsorRelative(...$relative));
    }

    /**
     * With `$at`, only a sponsorship assigned by then.
     */
    private function sponsorOf(Member $member, ?CarbonImmutable $at): ?Member
    {
        return $member->newQuery()
            ->where('program_id', $member->program_id)
            ->whereIn(
                $member->getKeyName(),
                $member->getConnection()->table(self::EDGES)
                    ->select('sponsor_id')
                    ->where('member_id', $member->getKey())
                    ->when($at !== null, static fn (Builder $query): Builder => $query->where('assigned_at', '<=', $at)),
            )
            ->first();
    }

    /**
     * With `$at`, only sponsorships assigned by then.
     *
     * @return EloquentCollection<int, Member>
     */
    private function sponsoredBy(Member $sponsor, ?CarbonImmutable $at): EloquentCollection
    {
        return $sponsor->newQuery()
            ->select($sponsor->qualifyColumn('*'))
            ->join(self::EDGES, self::EDGES.'.member_id', '=', $sponsor->getQualifiedKeyName())
            ->where(self::EDGES.'.sponsor_id', $sponsor->getKey())
            ->where($sponsor->qualifyColumn('program_id'), $sponsor->program_id)
            ->when($at !== null, static fn (EloquentBuilder $query): EloquentBuilder => $query->where(self::EDGES.'.assigned_at', '<=', $at))
            ->orderBy(self::EDGES.'.assigned_at')
            ->orderBy($sponsor->getQualifiedKeyName())
            ->get();
    }
}
