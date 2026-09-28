<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Genealogy;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use PandaBear\Mlm\Models\Member;

/**
 * @internal
 *
 * The closure-table mechanics the sponsor and placement genealogies share:
 * one tree's rows in `mlm_genealogy_paths`, and the locking a write to that
 * tree needs. Not a public API and not a tree framework — each genealogy
 * keeps its own edges, rules, exceptions and vocabulary. Only the storage
 * mechanics live here, so they exist once.
 *
 * Every path carries `effective_from`: the moment its descendant came below
 * its ancestor in this tree. The trees only grow — no edge is ever moved or
 * removed — so a path holds from that moment on, and the tree at a moment T
 * is the paths with `effective_from <= T`.
 */
final readonly class ClosureTree
{
    private const PATHS = 'mlm_genealogy_paths';

    /**
     * @param  'sponsor'|'placement'  $type  the `tree_type`, fixed by the genealogy that owns this tree
     */
    public function __construct(private string $type) {}

    /**
     * Fresh copies of both members, locked in key order — the same order for
     * every write, whichever way round they were passed.
     *
     * A shared lock: it keeps the two rows as read until the write commits,
     * and the program lock taken next is what makes writes wait for each
     * other. An exclusive lock here would deadlock: a write holding it while
     * it waits for the program blocks the write that holds the program, whose
     * foreign keys read-lock every member its new paths name.
     *
     * @return array{Member, Member} in the order given
     */
    public function lockMembers(Member $first, Member $second): array
    {
        $ids = [$first->getKey(), $second->getKey()];

        $locked = $first->newQuery()
            ->whereKey($ids)
            ->orderBy($first->getKeyName())
            ->sharedLock()
            ->get()
            ->keyBy($first->getKeyName());

        if ($locked->count() !== 2) {
            throw (new ModelNotFoundException)->setModel(Member::class, array_diff($ids, $locked->keys()->all()));
        }

        return [$locked->get($first->getKey()), $locked->get($second->getKey())];
    }

    /**
     * Locks the member's program row, after the members.
     *
     * Every write to a program's genealogies takes this lock, which is what
     * makes a cycle check safe: locking the two members alone would let two
     * writes over disjoint pairs each pass a check that the other breaks. The
     * cost is that genealogy writes within one program — sponsor and
     * placement alike — run one at a time.
     */
    public function lockProgram(Member $member): void
    {
        $member->program()->lockForUpdate()->firstOrFail();
    }

    /**
     * Whether `$descendantId` is in `$ancestorId`'s subtree. A member is in
     * its own subtree once it has a self path.
     */
    public function hasPath(Connection $connection, string $ancestorId, string $descendantId): bool
    {
        return $this->paths($connection)
            ->where('ancestor_id', $ancestorId)
            ->where('descendant_id', $descendantId)
            ->exists();
    }

    /**
     * The depth-0 path a member needs before paths can be joined through it,
     * written the first time the member takes part in this tree — never by a
     * read, and never because it takes part in another tree. It takes effect
     * at `$at`, the moment of that first write. It is structure only: no
     * member is its own sponsor or parent.
     *
     * @param  list<string>  $memberIds
     */
    public function ensureSelfPaths(Connection $connection, array $memberIds, CarbonImmutable $at): void
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

        $connection->table(self::PATHS)->insert(array_map(fn (string $id): array => [
            'tree_type' => $this->type,
            'ancestor_id' => $id,
            'descendant_id' => $id,
            'depth' => 0,
            'effective_from' => $at,
        ], $missing));
    }

    /**
     * Attaches `$childId`'s whole subtree beneath `$parentId`: every ancestor
     * of the parent (the parent included) becomes an ancestor of every member
     * of the subtree (the child included) — one set-based insert, however
     * deep the subtree.
     *
     * Every path it writes takes effect at `$at`, the moment of the edge that
     * joins the two: before that edge they were not connected, however long
     * each side had existed.
     */
    public function attach(Connection $connection, string $parentId, string $childId, CarbonImmutable $at): void
    {
        $connection->table(self::PATHS)->insertUsing(
            ['tree_type', 'ancestor_id', 'descendant_id', 'depth', 'effective_from'],
            $connection->table(self::PATHS.' as ancestry')
                ->crossJoin(self::PATHS.' as subtree')
                ->where('ancestry.tree_type', $this->type)
                ->where('ancestry.descendant_id', $parentId)
                ->where('subtree.tree_type', $this->type)
                ->where('subtree.ancestor_id', $childId)
                ->select(['ancestry.tree_type', 'ancestry.ancestor_id', 'subtree.descendant_id'])
                ->selectRaw('ancestry.depth + subtree.depth + 1')
                ->selectRaw('?', [$at]),
        );
    }

    /**
     * The tree as it stands, or, with `$at`, as it stood at that moment.
     *
     * @return Collection<int, array{Member, int}> nearest first, the member excluded
     */
    public function ancestors(Member $member, ?int $maxDepth, ?CarbonImmutable $at = null): Collection
    {
        return $this->relatives($member, 'descendant_id', 'ancestor_id', $maxDepth, $at);
    }

    /**
     * The tree as it stands, or, with `$at`, as it stood at that moment.
     *
     * @return Collection<int, array{Member, int}> nearest first, then by member key, the member excluded
     */
    public function descendants(Member $member, ?int $maxDepth, ?CarbonImmutable $at = null): Collection
    {
        return $this->relatives($member, 'ancestor_id', 'descendant_id', $maxDepth, $at);
    }

    /**
     * @return Collection<int, array{Member, int}>
     */
    private function relatives(Member $member, string $from, string $to, ?int $maxDepth, ?CarbonImmutable $at): Collection
    {
        if ($maxDepth !== null && $maxDepth < 1) {
            throw new InvalidArgumentException("A maximum depth must be 1 or more; {$maxDepth} given.");
        }

        $paths = $this->paths($member->getConnection())
            ->where($from, $member->getKey())
            // Depth 0 is the member's path to itself: structure, not a relative.
            ->where('depth', '>', 0)
            ->when($maxDepth !== null, static fn (Builder $query): Builder => $query->where('depth', '<=', $maxDepth))
            // A path holds from its effective_from on: nothing ends it.
            ->when($at !== null, static fn (Builder $query): Builder => $query->where('effective_from', '<=', $at))
            ->orderBy('depth')
            ->orderBy($to)
            ->get([$to, 'depth']);

        if ($paths->isEmpty()) {
            return new Collection;
        }

        // Scoped to the member's program: paths written through the supported
        // genealogies never cross programs, and a query never lets one through.
        $members = $member->newQuery()
            ->whereKey($paths->pluck($to)->all())
            ->where('program_id', $member->program_id)
            ->get()
            ->keyBy($member->getKeyName());

        return $paths
            ->filter(static fn (object $path): bool => $members->has($path->{$to}))
            ->map(static fn (object $path): array => [$members->get($path->{$to}), (int) $path->depth])
            ->values();
    }

    private function paths(Connection $connection): Builder
    {
        return $connection->table(self::PATHS)->where('tree_type', $this->type);
    }
}
