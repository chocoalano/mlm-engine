<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Genealogy;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
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
    /**
     * The `tree_type` of sponsor paths.
     */
    private const TREE = 'sponsor';

    private const EDGES = 'mlm_sponsor_edges';

    private const PATHS = 'mlm_genealogy_paths';

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
            [$member, $sponsor] = $this->lockMembers($member, $sponsor);

            if ($member->program_id !== $sponsor->program_id) {
                throw InvalidSponsorAssignment::differentPrograms($member, $sponsor);
            }

            // Every sponsor assignment in a program locks the program's row,
            // after the members. Locking the two members alone would let two
            // assignments over disjoint pairs each pass a cycle check that
            // the other one breaks.
            $member->program()->lockForUpdate()->firstOrFail();

            $current = $connection->table(self::EDGES)->where('member_id', $member->getKey())->value('sponsor_id');

            if (is_string($current)) {
                throw InvalidSponsorAssignment::alreadySponsored($member, $current, $sponsor);
            }

            // The sponsor must not already be in the member's subtree.
            $cycle = $this->paths($connection)
                ->where('ancestor_id', $member->getKey())
                ->where('descendant_id', $sponsor->getKey())
                ->exists();

            if ($cycle) {
                throw InvalidSponsorAssignment::cycle($member, $sponsor);
            }

            $this->ensureSelfPaths($connection, [$member->getKey(), $sponsor->getKey()]);

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

            // Every ancestor of the sponsor (the sponsor included) becomes an
            // ancestor of every member of the sponsored subtree (the member
            // included) — one set-based insert, however deep the subtree.
            $connection->table(self::PATHS)->insertUsing(
                ['tree_type', 'ancestor_id', 'descendant_id', 'depth'],
                $connection->table(self::PATHS.' as ancestry')
                    ->crossJoin(self::PATHS.' as subtree')
                    ->where('ancestry.tree_type', self::TREE)
                    ->where('ancestry.descendant_id', $sponsor->getKey())
                    ->where('subtree.tree_type', self::TREE)
                    ->where('subtree.ancestor_id', $member->getKey())
                    ->select(['ancestry.tree_type', 'ancestry.ancestor_id', 'subtree.descendant_id'])
                    ->selectRaw('ancestry.depth + subtree.depth + 1'),
            );

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
        return $this->relatives($member, 'descendant_id', 'ancestor_id', $maxDepth);
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
        return $this->relatives($member, 'ancestor_id', 'descendant_id', $maxDepth);
    }

    /**
     * @return Collection<int, SponsorRelative>
     */
    private function relatives(Member $member, string $from, string $to, ?int $maxDepth): Collection
    {
        if ($maxDepth !== null && $maxDepth < 1) {
            throw new InvalidArgumentException("A maximum depth must be 1 or more; {$maxDepth} given.");
        }

        $paths = $this->paths($member->getConnection())
            ->where($from, $member->getKey())
            // Depth 0 is the member's path to itself: structure, not a relative.
            ->where('depth', '>', 0)
            ->when($maxDepth !== null, static fn (Builder $query): Builder => $query->where('depth', '<=', $maxDepth))
            ->orderBy('depth')
            ->orderBy($to)
            ->get([$to, 'depth']);

        if ($paths->isEmpty()) {
            return new Collection;
        }

        // Scoped to the member's program: paths written by assignSponsor()
        // never cross programs, and a query never lets one through if they did.
        $members = $member->newQuery()
            ->whereKey($paths->pluck($to)->all())
            ->where('program_id', $member->program_id)
            ->get()
            ->keyBy($member->getKeyName());

        return $paths
            ->filter(static fn (object $path): bool => $members->has($path->{$to}))
            ->map(static fn (object $path): SponsorRelative => new SponsorRelative($members->get($path->{$to}), (int) $path->depth))
            ->values();
    }

    /**
     * Fresh copies of both members, locked in key order — the same order for
     * every assignment, whichever way round they were passed — so two
     * assignments sharing a member cannot deadlock on each other.
     *
     * @return array{Member, Member}
     */
    private function lockMembers(Member $member, Member $sponsor): array
    {
        $ids = [$member->getKey(), $sponsor->getKey()];

        $locked = $member->newQuery()
            ->whereKey($ids)
            ->orderBy($member->getKeyName())
            ->lockForUpdate()
            ->get()
            ->keyBy($member->getKeyName());

        if ($locked->count() !== 2) {
            throw (new ModelNotFoundException)->setModel(Member::class, array_diff($ids, $locked->keys()->all()));
        }

        return [$locked->get($member->getKey()), $locked->get($sponsor->getKey())];
    }

    /**
     * The depth-0 path a member needs before paths can be joined through it.
     * Written the first time a member takes part in the sponsor tree, never
     * by a read.
     *
     * @param  list<string>  $memberIds
     */
    private function ensureSelfPaths(Connection $connection, array $memberIds): void
    {
        $existing = $this->paths($connection)
            ->whereIn('ancestor_id', $memberIds)
            ->where('depth', 0)
            ->pluck('ancestor_id')
            ->all();

        $missing = array_values(array_diff($memberIds, $existing));

        if ($missing === []) {
            return;
        }

        $connection->table(self::PATHS)->insert(array_map(static fn (string $id): array => [
            'tree_type' => self::TREE,
            'ancestor_id' => $id,
            'descendant_id' => $id,
            'depth' => 0,
        ], $missing));
    }

    private function paths(Connection $connection): Builder
    {
        return $connection->table(self::PATHS)->where('tree_type', self::TREE);
    }
}
