<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PandaBear\Mlm\Support\PandaMlmConfig;

/**
 * Gives every genealogy path the moment it took effect (ADR-012).
 *
 * Paths written before this migration are given their real history, rebuilt
 * from the direct edges' own timestamps — never the time the migration runs.
 * Each tree is replayed from its edges, oldest first, into a scratch table the
 * way the genealogies write it: an edge's moment for every path it creates, a
 * member's first edge for its self path. The replayed structure must be
 * exactly the stored one; if it is not, the paths were written outside the
 * genealogies, they have no history to recover, and the migration stops
 * rather than invent one.
 */
return new class extends Migration
{
    /**
     * Where each tree is replayed. Dropped before the migration returns.
     */
    private const REPLAY = 'mlm_genealogy_paths_replay_000009';

    /**
     * Named here: the generated name is longer than MySQL allows.
     */
    private const DESCENDANTS_AT = 'mlm_genealogy_paths_descendants_at_index';

    /**
     * Each tree and the direct edges its paths come from.
     */
    private const TREES = [
        'sponsor' => ['edges' => 'mlm_sponsor_edges', 'parent' => 'sponsor_id', 'at' => 'assigned_at'],
        'placement' => ['edges' => 'mlm_placement_edges', 'parent' => 'parent_id', 'at' => 'placed_at'],
    ];

    /**
     * Edges read per query while replaying: memory stays bounded however
     * large the trees are.
     */
    private const CHUNK = 500;

    /**
     * The migrator builds this migration's schema on the connection named
     * here, which is the one the package's models read from.
     */
    public function getConnection(): ?string
    {
        return Container::getInstance()->make(PandaMlmConfig::class)->databaseConnection();
    }

    public function up(): void
    {
        $db = Schema::getConnection();

        // Nullable until every path has its moment. MySQL cannot roll a
        // failed run's schema changes back, so a rerun finds the column there.
        if (! Schema::hasColumn('mlm_genealogy_paths', 'effective_from')) {
            Schema::table('mlm_genealogy_paths', function (Blueprint $table): void {
                $table->dateTime('effective_from')->nullable();
            });
        }

        $this->createReplay();

        try {
            $this->refuseUnknownTrees($db);

            foreach (self::TREES as $tree => $source) {
                $this->replay($db, $tree, $source);
                $this->refuseMismatch($db, $tree);
            }

            // Every path takes the moment its replayed twin took.
            $db->table('mlm_genealogy_paths')->update([
                'effective_from' => $db->raw('('.$db->table(self::REPLAY)
                    ->select('effective_from')
                    ->whereColumn(self::REPLAY.'.tree_type', 'mlm_genealogy_paths.tree_type')
                    ->whereColumn(self::REPLAY.'.ancestor_id', 'mlm_genealogy_paths.ancestor_id')
                    ->whereColumn(self::REPLAY.'.descendant_id', 'mlm_genealogy_paths.descendant_id')
                    ->toSql().')'),
            ]);
        } catch (Throwable $exception) {
            // Best effort: after a failed statement PostgreSQL refuses even
            // this, but its rollback removes the scratch table anyway — and the
            // error that stopped the migration is the one worth reporting.
            try {
                Schema::dropIfExists(self::REPLAY);
            } catch (Throwable) {
            }

            throw $exception;
        }

        Schema::dropIfExists(self::REPLAY);

        Schema::table('mlm_genealogy_paths', function (Blueprint $table): void {
            $table->dateTime('effective_from')->nullable(false)->change();
        });

        // "Descendants of X as of T": a range over effective_from within one
        // ancestor, instead of reading the whole current subtree and
        // discarding what came later. Ancestors need no counterpart — a
        // member has only as many as the tree is deep.
        Schema::table('mlm_genealogy_paths', function (Blueprint $table): void {
            $table->index(['tree_type', 'ancestor_id', 'effective_from', 'depth'], self::DESCENDANTS_AT);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::REPLAY);

        Schema::table('mlm_genealogy_paths', function (Blueprint $table): void {
            $table->dropIndex(self::DESCENDANTS_AT);
        });

        Schema::table('mlm_genealogy_paths', function (Blueprint $table): void {
            $table->dropColumn('effective_from');
        });
    }

    private function createReplay(): void
    {
        // Left behind only by a failed run on MySQL; never needed again.
        Schema::dropIfExists(self::REPLAY);

        Schema::create(self::REPLAY, function (Blueprint $table): void {
            $table->string('tree_type', 20);
            $table->ulid('ancestor_id');
            $table->ulid('descendant_id');
            $table->unsignedInteger('depth');
            $table->dateTime('effective_from');

            // A member's subtree, and its ancestry, without a scan.
            $table->primary(['tree_type', 'ancestor_id', 'descendant_id']);
            $table->index(['tree_type', 'descendant_id']);
        });
    }

    /**
     * Rebuilds one tree from its edges, oldest first, as the genealogy writes
     * it: every path an edge creates takes that edge's moment, and a member's
     * self path the moment of its first edge. Edges in one second may replay
     * in either order — the paths they create take that same second — so the
     * edge id only makes the order repeatable.
     *
     * @param  array{edges: string, parent: string, at: string}  $source
     */
    private function replay(Connection $db, string $tree, array $source): void
    {
        $last = null;

        do {
            $edges = $db->table($source['edges'])
                ->select(['id', 'member_id', "{$source['parent']} as parent_id", "{$source['at']} as at"])
                ->when($last !== null, static fn (Builder $query): Builder => $query->where(
                    static fn (Builder $after): Builder => $after
                        ->where($source['at'], '>', $last->at)
                        ->orWhere(static fn (Builder $same): Builder => $same->where($source['at'], $last->at)->where('id', '>', $last->id)),
                ))
                ->orderBy($source['at'])
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->get();

            foreach ($edges as $edge) {
                $this->replayEdge($db, $tree, $edge);
            }

            $last = $edges->last();
        } while ($edges->count() === self::CHUNK);
    }

    private function replayEdge(Connection $db, string $tree, object $edge): void
    {
        // A parent already below its member: the edges close a cycle, which
        // no supported write can produce.
        if ($this->replayed($db, $tree)->where('ancestor_id', $edge->member_id)->where('descendant_id', $edge->parent_id)->exists()) {
            throw $this->inconsistent($tree, "edge {$edge->id} closes a cycle");
        }

        $members = [$edge->parent_id, $edge->member_id];
        $known = $this->replayed($db, $tree)->whereIn('ancestor_id', $members)->where('depth', 0)->pluck('ancestor_id')->all();

        foreach (array_values(array_diff($members, $known)) as $member) {
            $db->table(self::REPLAY)->insert([
                'tree_type' => $tree,
                'ancestor_id' => $member,
                'descendant_id' => $member,
                'depth' => 0,
                'effective_from' => $edge->at,
            ]);
        }

        // Every ancestor of the parent over every member of the child's
        // subtree, as the genealogy attaches a subtree.
        $db->table(self::REPLAY)->insertUsing(
            ['tree_type', 'ancestor_id', 'descendant_id', 'depth', 'effective_from'],
            $db->table(self::REPLAY.' as ancestry')
                ->crossJoin(self::REPLAY.' as subtree')
                ->where('ancestry.tree_type', $tree)
                ->where('ancestry.descendant_id', $edge->parent_id)
                ->where('subtree.tree_type', $tree)
                ->where('subtree.ancestor_id', $edge->member_id)
                ->select(['ancestry.tree_type', 'ancestry.ancestor_id', 'subtree.descendant_id'])
                ->selectRaw('ancestry.depth + subtree.depth + 1')
                ->selectRaw('?', [$edge->at]),
        );
    }

    /**
     * The stored paths must be exactly the replayed ones: every pair, at the
     * same depth, and nothing else.
     */
    private function refuseMismatch(Connection $db, string $tree): void
    {
        $stored = $db->table('mlm_genealogy_paths')->where('tree_type', $tree)->count();
        $replayed = $this->replayed($db, $tree)->count();

        $matching = $db->table('mlm_genealogy_paths as stored')
            ->join(self::REPLAY.' as replayed', static fn (JoinClause $join): JoinClause => $join
                ->on('replayed.tree_type', '=', 'stored.tree_type')
                ->on('replayed.ancestor_id', '=', 'stored.ancestor_id')
                ->on('replayed.descendant_id', '=', 'stored.descendant_id')
                ->on('replayed.depth', '=', 'stored.depth'))
            ->where('stored.tree_type', $tree)
            ->count();

        if ($stored !== $replayed || $matching !== $stored) {
            throw $this->inconsistent($tree, "the edges imply {$replayed} paths, {$stored} are stored, and {$matching} of those match");
        }
    }

    private function refuseUnknownTrees(Connection $db): void
    {
        $unknown = $db->table('mlm_genealogy_paths')->whereNotIn('tree_type', array_keys(self::TREES))->count();

        if ($unknown > 0) {
            throw new RuntimeException("Existing genealogy edges and closure paths are inconsistent: {$unknown} paths belong to no sponsor or placement tree, so there is no edge to date them by. No effective_from was written.");
        }
    }

    private function replayed(Connection $db, string $tree): Builder
    {
        return $db->table(self::REPLAY)->where('tree_type', $tree);
    }

    private function inconsistent(string $tree, string $detail): RuntimeException
    {
        return new RuntimeException(
            "Existing {$tree} genealogy edges and closure paths are inconsistent: {$detail}. "
            .'Paths written outside SponsorGenealogy and PlacementGenealogy have no history to recover, so no effective_from was written. '
            .'Make the paths match the edges, then run the migration again.',
        );
    }
};
