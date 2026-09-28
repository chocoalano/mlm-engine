<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Genealogy;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use PandaBear\Mlm\Exceptions\InvalidSponsorAssignment;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\SponsorEdge;

/**
 * Who sponsored whom, within one program.
 *
 * A member has at most one direct sponsor, assigned once; a sponsor may
 * sponsor any number of members; members without a sponsor are roots, and a
 * program may have many. The sponsor tree is never a placement structure.
 *
 * Direct sponsorships are edges. Every ancestor/descendant pair they imply is
 * kept in a closure table beside them, so ancestry is read, never walked.
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

            $this->tree->ensureSelfPaths($connection, [$member->getKey(), $sponsor->getKey()]);

            $edge = new SponsorEdge;
            $id = $edge->newUniqueId();
            $now = $edge->freshTimestamp();

            $connection->table(self::EDGES)->insert([
                'id' => $id,
                'member_id' => $member->getKey(),
                'sponsor_id' => $sponsor->getKey(),
                'assigned_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->tree->attach($connection, $sponsor->getKey(), $member->getKey());

            return SponsorEdge::on($connection->getName())->findOrFail($id);
        });
    }

    public function directSponsor(Member $member): ?Member
    {
        return $member->newQuery()
            ->where('program_id', $member->program_id)
            ->whereIn(
                $member->getKeyName(),
                $member->getConnection()->table(self::EDGES)->select('sponsor_id')->where('member_id', $member->getKey()),
            )
            ->first();
    }

    /**
     * The members `$sponsor` sponsored directly, in the order they were
     * sponsored.
     *
     * @return EloquentCollection<int, Member>
     */
    public function directMembers(Member $sponsor): EloquentCollection
    {
        return $sponsor->newQuery()
            ->select($sponsor->qualifyColumn('*'))
            ->join(self::EDGES, self::EDGES.'.member_id', '=', $sponsor->getQualifiedKeyName())
            ->where(self::EDGES.'.sponsor_id', $sponsor->getKey())
            ->where($sponsor->qualifyColumn('program_id'), $sponsor->program_id)
            ->orderBy(self::EDGES.'.assigned_at')
            ->orderBy($sponsor->getQualifiedKeyName())
            ->get();
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
}
