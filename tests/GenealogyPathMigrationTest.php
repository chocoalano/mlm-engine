<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Upgrading a v0.1.0 database: migration 000009 gives every existing path the
 * moment it really took effect, rebuilt from the edges' own timestamps — or,
 * if the paths do not match the edges, stops without inventing any.
 *
 * The v0.1.0 database is built the way v0.1.0 left it: its eight migrations,
 * then edges and paths written row by row, with no effective_from — never
 * through the current genealogy services.
 */
final class GenealogyPathMigrationTest extends TestCase
{
    use BuildsGenealogies;

    private const MIGRATION = '2026_09_28_000009_add_effective_from_to_mlm_genealogy_paths';

    private const REPLAY = 'mlm_genealogy_paths_replay_000009';

    private const TREES = [
        'sponsor' => ['edges' => 'mlm_sponsor_edges', 'parent' => 'sponsor_id', 'at' => 'assigned_at'],
        'placement' => ['edges' => 'mlm_placement_edges', 'parent' => 'parent_id', 'at' => 'placed_at'],
    ];

    /**
     * @var array<string, string> member code => id
     */
    private array $ids = [];

    public function test_existing_paths_take_the_moment_their_chain_was_completed(): void
    {
        $this->legacyDatabase('A', 'B', 'C', 'D', 'E', 'F');

        // Sponsor: B -> C -> D grows first; A, which already sponsored E,
        // takes B's subtree in March; E sponsors F in April.
        $this->legacyEdge('sponsor', 'B', 'C', '2026-01-01 00:00:00');
        $this->legacyEdge('sponsor', 'A', 'E', '2026-01-15 00:00:00');
        $this->legacyEdge('sponsor', 'C', 'D', '2026-02-01 00:00:00');
        $this->legacyEdge('sponsor', 'A', 'B', '2026-03-01 00:00:00');
        $this->legacyEdge('sponsor', 'E', 'F', '2026-04-01 00:00:00');

        // Placement: the same members, other moments, another shape.
        $this->legacyEdge('placement', 'F', 'D', '2026-04-20 00:00:00');
        $this->legacyEdge('placement', 'D', 'A', '2026-05-01 00:00:00');
        $this->legacyEdge('placement', 'A', 'B', '2026-05-02 00:00:00');

        $this->legacyPaths();
        $this->upgrade();

        $this->assertSame([
            'A > A @0' => '2026-01-15 00:00:00',
            'A > B @1' => '2026-03-01 00:00:00',
            'A > C @2' => '2026-03-01 00:00:00',
            'A > D @3' => '2026-03-01 00:00:00',
            'A > E @1' => '2026-01-15 00:00:00',
            'A > F @2' => '2026-04-01 00:00:00',
            'B > B @0' => '2026-01-01 00:00:00',
            'B > C @1' => '2026-01-01 00:00:00',
            'B > D @2' => '2026-02-01 00:00:00',
            'C > C @0' => '2026-01-01 00:00:00',
            'C > D @1' => '2026-02-01 00:00:00',
            'D > D @0' => '2026-02-01 00:00:00',
            'E > E @0' => '2026-01-15 00:00:00',
            'E > F @1' => '2026-04-01 00:00:00',
            'F > F @0' => '2026-04-01 00:00:00',
        ], $this->pathMoments('sponsor'));

        $this->assertSame([
            'A > A @0' => '2026-05-01 00:00:00',
            'A > B @1' => '2026-05-02 00:00:00',
            'B > B @0' => '2026-05-02 00:00:00',
            'D > A @1' => '2026-05-01 00:00:00',
            'D > B @2' => '2026-05-02 00:00:00',
            'D > D @0' => '2026-04-20 00:00:00',
            'F > A @2' => '2026-05-01 00:00:00',
            'F > B @3' => '2026-05-02 00:00:00',
            'F > D @1' => '2026-04-20 00:00:00',
            'F > F @0' => '2026-04-20 00:00:00',
        ], $this->pathMoments('placement'));
    }

    public function test_the_upgraded_paths_answer_historical_queries(): void
    {
        $this->legacyDatabase('A', 'B', 'C');
        $this->legacyEdge('sponsor', 'B', 'C', '2026-01-01 00:00:00');
        $this->legacyEdge('sponsor', 'A', 'B', '2026-03-01 00:00:00');
        $this->legacyPaths();
        $this->upgrade();

        [$a, $c] = [$this->fresh('A'), $this->fresh('C')];

        $this->assertSame(['B@1'], $this->relatives($this->genealogy()->ancestorsAt($c, CarbonImmutable::parse('2026-02-28 23:59:59'))));
        $this->assertSame(['B@1', 'A@2'], $this->relatives($this->genealogy()->ancestorsAt($c, CarbonImmutable::parse('2026-03-01 00:00:00'))));
        $this->assertSame([], $this->relatives($this->genealogy()->descendantsAt($a, CarbonImmutable::parse('2026-02-28 23:59:59'))));
        $this->assertTrue($this->genealogy()->directSponsorAt($this->fresh('B'), CarbonImmutable::parse('2026-03-01 00:00:00'))?->is($a));
        $this->assertNull($this->genealogy()->directSponsorAt($this->fresh('B'), CarbonImmutable::parse('2026-02-28 23:59:59')));
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function sameSecondOrders(): array
    {
        return [
            'the parent edge has the lower id' => [['X>Y', 'Y>Z']],
            'the child edge has the lower id' => [['Y>Z', 'X>Y']],
        ];
    }

    /**
     * @param  list<string>  $idOrder  the edges, lowest id first
     */
    #[DataProvider('sameSecondOrders')]
    public function test_edges_in_one_second_backfill_that_second_whichever_replays_first(array $idOrder): void
    {
        $this->legacyDatabase('W', 'X', 'Y', 'Z');
        $ids = array_combine($idOrder, ['00000000000000000000000001', '00000000000000000000000002']);

        $this->legacyEdge('sponsor', 'X', 'Y', '2026-06-01 12:00:00', $ids['X>Y']);
        $this->legacyEdge('sponsor', 'Y', 'Z', '2026-06-01 12:00:00', $ids['Y>Z']);
        $this->legacyEdge('sponsor', 'W', 'X', '2026-06-01 12:00:01');
        $this->legacyPaths();
        $this->upgrade();

        $this->assertSame([
            'W > W @0' => '2026-06-01 12:00:01',
            'W > X @1' => '2026-06-01 12:00:01',
            'W > Y @2' => '2026-06-01 12:00:01',
            'W > Z @3' => '2026-06-01 12:00:01',
            'X > X @0' => '2026-06-01 12:00:00',
            'X > Y @1' => '2026-06-01 12:00:00',
            'X > Z @2' => '2026-06-01 12:00:00',
            'Y > Y @0' => '2026-06-01 12:00:00',
            'Y > Z @1' => '2026-06-01 12:00:00',
            'Z > Z @0' => '2026-06-01 12:00:00',
        ], $this->pathMoments('sponsor'));
    }

    public function test_edges_replay_in_time_order_whatever_their_ids(): void
    {
        // Nothing promises edge ids in time order. Replayed by id, A -> B
        // would come first, and A -> C would wrongly take B -> C's January.
        $this->legacyDatabase('A', 'B', 'C');
        $this->legacyEdge('sponsor', 'A', 'B', '2026-03-01 00:00:00', '00000000000000000000000001');
        $this->legacyEdge('sponsor', 'B', 'C', '2026-01-01 00:00:00', '00000000000000000000000002');
        $this->legacyPaths();
        $this->upgrade();

        $this->assertSame([
            'A > A @0' => '2026-03-01 00:00:00',
            'A > B @1' => '2026-03-01 00:00:00',
            'A > C @2' => '2026-03-01 00:00:00',
            'B > B @0' => '2026-01-01 00:00:00',
            'B > C @1' => '2026-01-01 00:00:00',
            'C > C @0' => '2026-01-01 00:00:00',
        ], $this->pathMoments('sponsor'));
    }

    public function test_the_replay_reads_edges_in_chunks_and_keeps_their_order(): void
    {
        // More edges than one replay chunk (500), three to a second, so chunk
        // boundaries fall inside a second: each chunk must carry on exactly
        // where the previous one stopped.
        $this->legacyDatabase(...array_map(static fn (int $i): string => "M{$i}", range(0, 1200)));
        $start = CarbonImmutable::parse('2026-07-01 10:00:00');

        foreach (range(1, 1200) as $i) {
            $this->legacyEdge('placement', 'M'.intdiv($i - 1, 3), "M{$i}", $start->addSeconds(intdiv($i, 3))->toDateTimeString());
        }

        $this->legacyPaths();
        $this->upgrade();

        $at = DB::table('mlm_placement_edges')->pluck('placed_at', 'member_id')->map(static fn (mixed $moment): string => (string) $moment)->all();
        $parents = DB::table('mlm_placement_edges')->pluck('parent_id', 'member_id')->all();
        $paths = DB::table('mlm_genealogy_paths')->where('tree_type', 'placement')->get();

        $this->assertCount(DB::table('mlm_genealogy_paths')->count(), $paths);

        foreach ($paths as $path) {
            if ((int) $path->depth === 0) {
                // The member's first edge: its own, or the first under it.
                $edges = [$at[$path->descendant_id] ?? null, ...array_map(
                    static fn (string $child): string => $at[$child],
                    array_keys($parents, $path->descendant_id, true),
                )];
                $this->assertSame(min(array_filter($edges)), (string) $path->effective_from);

                continue;
            }

            // The latest edge on the chain.
            $latest = '';

            for ($member = $path->descendant_id; $member !== $path->ancestor_id; $member = $parents[$member]) {
                $latest = max($latest, $at[$member]);
            }

            $this->assertSame($latest, (string) $path->effective_from);
        }
    }

    /**
     * @return array<string, array{Closure(self): void}>
     */
    public static function inconsistencies(): array
    {
        return [
            'a missing indirect path' => [static fn (self $test) => $test->deletePath('sponsor', 'A', 'C')],
            'a path no edge implies' => [static fn (self $test) => $test->insertPath('sponsor', 'D', 'B', 1)],
            'a path at the wrong depth' => [static fn (self $test) => DB::table('mlm_genealogy_paths')
                ->where('tree_type', 'sponsor')
                ->where('ancestor_id', $test->id('A'))
                ->where('descendant_id', $test->id('C'))
                ->update(['depth' => 5])],
            'a placement path written as sponsor' => [static fn (self $test) => $test->insertPath('sponsor', 'D', 'A', 1)],
            'a path of an unknown tree' => [static fn (self $test) => $test->insertPath('binary', 'A', 'B', 1)],
            'edges that close a cycle' => [static fn (self $test) => $test->legacyEdge('sponsor', 'C', 'A', '2026-04-01 00:00:00')],
        ];
    }

    /**
     * @param  Closure(self): void  $corrupt
     */
    #[DataProvider('inconsistencies')]
    public function test_paths_that_do_not_match_the_edges_stop_the_upgrade(Closure $corrupt): void
    {
        $this->legacyDatabase('A', 'B', 'C', 'D');
        $this->legacyEdge('sponsor', 'A', 'B', '2026-01-01 00:00:00');
        $this->legacyEdge('sponsor', 'B', 'C', '2026-02-01 00:00:00');
        $this->legacyEdge('placement', 'D', 'A', '2026-03-01 00:00:00');
        $this->legacyPaths();
        $corrupt($this);

        $before = $this->pathStructure();

        try {
            Artisan::call('migrate');
            $this->fail('The upgrade accepted paths that do not match the edges.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('genealogy edges and closure paths are inconsistent', $exception->getMessage());
        }

        // Nothing invented: the paths are as they were and carry no moment,
        // the scratch table is gone, and the migration can run again.
        $this->assertSame($before, $this->pathStructure());
        $this->assertFalse(Schema::hasTable(self::REPLAY));
        $this->assertFalse(DB::table('migrations')->where('migration', self::MIGRATION)->exists());

        if (Schema::hasColumn('mlm_genealogy_paths', 'effective_from')) {
            $this->assertSame(0, DB::table('mlm_genealogy_paths')->whereNotNull('effective_from')->count());
        }
    }

    public function test_the_upgrade_runs_again_once_the_paths_are_corrected(): void
    {
        $this->legacyDatabase('A', 'B', 'C');
        $this->legacyEdge('sponsor', 'A', 'B', '2026-01-01 00:00:00');
        $this->legacyEdge('sponsor', 'B', 'C', '2026-02-01 00:00:00');
        $this->legacyPaths();
        $this->deletePath('sponsor', 'A', 'C');

        try {
            Artisan::call('migrate');
            $this->fail('The upgrade accepted a missing path.');
        } catch (RuntimeException) {
        }

        $this->insertPath('sponsor', 'A', 'C', 2);
        $this->upgrade();

        $this->assertSame('2026-02-01 00:00:00', $this->pathMoments('sponsor')['A > C @2']);
        $this->assertFalse(Schema::hasTable(self::REPLAY));
    }

    public function test_rolling_the_upgrade_back_keeps_the_paths_and_it_can_run_again(): void
    {
        $this->legacyDatabase('A', 'B', 'C');
        $this->legacyEdge('sponsor', 'B', 'C', '2026-01-01 00:00:00');
        $this->legacyEdge('sponsor', 'A', 'B', '2026-03-01 00:00:00');
        $this->legacyPaths();
        $this->upgrade();
        $upgraded = $this->pathMoments('sponsor');
        $structure = $this->pathStructure();

        $this->artisan('migrate:rollback', [
            '--path' => dirname(__DIR__).'/database/migrations/'.self::MIGRATION.'.php',
            '--realpath' => true,
        ])->assertSuccessful();

        $this->assertFalse(Schema::hasColumn('mlm_genealogy_paths', 'effective_from'));
        $this->assertNotContains(
            ['tree_type', 'ancestor_id', 'effective_from', 'depth'],
            collect(Schema::getIndexes('mlm_genealogy_paths'))->pluck('columns')->all(),
        );
        $this->assertSame($structure, $this->pathStructure());

        $this->upgrade();

        $this->assertSame($upgraded, $this->pathMoments('sponsor'));
    }

    public function test_a_database_without_genealogy_upgrades_to_a_non_null_column(): void
    {
        $this->legacyDatabase();
        $this->upgrade();

        $column = collect(Schema::getColumns('mlm_genealogy_paths'))->firstWhere('name', 'effective_from');

        $this->assertIsArray($column);
        $this->assertFalse($column['nullable']);
        $this->assertSame(0, DB::table('mlm_genealogy_paths')->count());
        $this->assertFalse(Schema::hasTable(self::REPLAY));
    }

    public function id(string $code): string
    {
        return $this->ids[$code];
    }

    public function deletePath(string $tree, string $ancestor, string $descendant): void
    {
        DB::table('mlm_genealogy_paths')
            ->where('tree_type', $tree)
            ->where('ancestor_id', $this->id($ancestor))
            ->where('descendant_id', $this->id($descendant))
            ->delete();
    }

    public function insertPath(string $tree, string $ancestor, string $descendant, int $depth): void
    {
        DB::table('mlm_genealogy_paths')->insert([
            'tree_type' => $tree,
            'ancestor_id' => $this->id($ancestor),
            'descendant_id' => $this->id($descendant),
            'depth' => $depth,
        ]);
    }

    /**
     * A v0.1.0 edge, written as v0.1.0 wrote it: `$parent` over `$child` at
     * `$at`.
     */
    public function legacyEdge(string $tree, string $parent, string $child, string $at, ?string $id = null): void
    {
        $source = self::TREES[$tree];

        DB::table($source['edges'])->insert([
            'id' => $id ?? strtolower((string) Str::ulid()),
            'member_id' => $this->id($child),
            $source['parent'] => $this->id($parent),
            $source['at'] => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /**
     * The v0.1.0 schema — migrations 000001 to 000008 — and members to link.
     */
    private function legacyDatabase(string ...$codes): void
    {
        $this->artisan('migrate', [
            '--path' => array_map(
                static fn (string $migration): string => dirname(__DIR__)."/database/migrations/2026_09_28_{$migration}.php",
                ['000001_create_mlm_programs_table', '000002_create_mlm_members_table', '000003_create_mlm_plans_table', '000004_create_mlm_plan_versions_table', '000005_create_mlm_sponsor_edges_table', '000006_create_mlm_genealogy_paths_table', '000007_create_mlm_placement_edges_table', '000008_create_mlm_volume_entries_table'],
            ),
            '--realpath' => true,
        ])->assertSuccessful();

        $this->assertFalse(Schema::hasColumn('mlm_genealogy_paths', 'effective_from'));

        $program = Program::factory()->create();
        $rows = [];

        foreach ($codes as $code) {
            $this->ids[$code] = strtolower((string) Str::ulid());
            $rows[] = [
                'id' => $this->ids[$code],
                'program_id' => $program->id,
                'member_code' => $code,
                'joined_at' => '2025-12-01 00:00:00',
                'created_at' => '2025-12-01 00:00:00',
                'updated_at' => '2025-12-01 00:00:00',
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('mlm_members')->insert($chunk);
        }
    }

    /**
     * Every path v0.1.0 kept for the edges already written — each member's
     * self path and one path to every ancestor — with no moment.
     */
    private function legacyPaths(): void
    {
        foreach (self::TREES as $tree => $source) {
            /** @var array<string, string> $parents */
            $parents = DB::table($source['edges'])->pluck($source['parent'], 'member_id')->all();
            $rows = [];

            foreach (array_unique([...array_keys($parents), ...array_values($parents)]) as $member) {
                $rows[] = ['tree_type' => $tree, 'ancestor_id' => $member, 'descendant_id' => $member, 'depth' => 0];

                // Bounded: a cycle in the edges only needs to reach the
                // migration, which must refuse it.
                for ($ancestor = $member, $depth = 1; isset($parents[$ancestor]) && $depth <= count($parents); $depth++) {
                    $ancestor = $parents[$ancestor];

                    if ($ancestor === $member) {
                        break;
                    }

                    $rows[] = ['tree_type' => $tree, 'ancestor_id' => $ancestor, 'descendant_id' => $member, 'depth' => $depth];
                }
            }

            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('mlm_genealogy_paths')->insert($chunk);
            }
        }
    }

    private function upgrade(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $this->assertTrue(DB::table('migrations')->where('migration', self::MIGRATION)->exists());
    }

    /**
     * @return array<string, int> "tree: ancestor > descendant" => depth
     */
    private function pathStructure(): array
    {
        $structure = DB::table('mlm_genealogy_paths')->get()
            ->mapWithKeys(static fn (object $path): array => ["{$path->tree_type}: {$path->ancestor_id} > {$path->descendant_id}" => (int) $path->depth])
            ->all();

        ksort($structure);

        return $structure;
    }

    private function fresh(string $code): Member
    {
        return Member::query()->findOrFail($this->id($code));
    }
}
