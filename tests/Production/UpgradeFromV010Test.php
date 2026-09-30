<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Production;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PandaBear\Mlm\Genealogy\PlacementGenealogy;
use PandaBear\Mlm\Genealogy\SponsorGenealogy;
use PandaBear\Mlm\Genealogy\SponsorRelative;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Planning\PlanVersionStatus;
use PandaBear\Mlm\Program\ProgramManager;
use PandaBear\Mlm\Tests\Concerns\RecordsVolume;
use PandaBear\Mlm\Tests\TestCase;
use PandaBear\Mlm\Volume\VolumeTotals;

/**
 * An installation that began on v0.1.0 — the eight migrations it shipped,
 * never changed since, and its rows written the way v0.1.0 wrote them —
 * upgraded to the current schema: every row survives unchanged, every new
 * table starts empty, and the package reads and extends the old data.
 */
final class UpgradeFromV010Test extends TestCase
{
    use RecordsVolume;

    /**
     * The migrations tagged v0.1.0.
     */
    private const V010 = [
        '000001_create_mlm_programs_table', '000002_create_mlm_members_table', '000003_create_mlm_plans_table',
        '000004_create_mlm_plan_versions_table', '000005_create_mlm_sponsor_edges_table', '000006_create_mlm_genealogy_paths_table',
        '000007_create_mlm_placement_edges_table', '000008_create_mlm_volume_entries_table',
    ];

    /**
     * v0.1.0's tables and the rows they held before the upgrade.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    private array $before = [];

    public function test_a_v0_1_0_installation_upgrades_with_its_data_intact_and_usable(): void
    {
        $this->artisan('migrate', [
            '--path' => array_map(static fn (string $migration): string => dirname(__DIR__, 2)."/database/migrations/2026_09_28_{$migration}.php", self::V010),
            '--realpath' => true,
        ])->assertSuccessful();

        $this->assertFalse(Schema::hasTable('mlm_wallets'));
        $ids = $this->legacyRows();

        $this->before = $this->snapshot();
        $this->artisan('migrate')->assertSuccessful();

        // Every v0.1.0 row, as it was — genealogy paths gain only their
        // effective moment.
        $upgraded = $this->snapshot();

        foreach ($this->before as $table => $rows) {
            $after = $upgraded[$table];
            $this->assertCount(count($rows), $after, "{$table} lost or gained rows.");

            foreach ($rows as $index => $row) {
                $now = $after[$index];

                if ($table === 'mlm_genealogy_paths') {
                    $this->assertNotNull($now['effective_from']);
                    unset($now['effective_from']);
                }

                $this->assertEquals($row, $now, "{$table} changed a row.");
            }
        }

        // Nothing was backfilled into a table v0.1.0 never had.
        foreach (['mlm_wallets', 'mlm_ledger_accounts', 'mlm_ledger_transactions', 'mlm_calculation_runs', 'mlm_commissions', 'mlm_binary_placement_positions', 'mlm_matrix_networks', 'mlm_commission_periods', 'mlm_payout_requests', 'mlm_payout_batches'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} was not created.");
            $this->assertSame(0, DB::table($table)->count(), "{$table} was backfilled.");
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        }

        // The package reads the old data…
        $alice = Member::query()->findOrFail($ids['ALICE']);
        $this->assertSame(
            [['BOB', 1], ['CAROL', 2]],
            $this->app->make(SponsorGenealogy::class)->descendants($alice)->map(static fn (SponsorRelative $relative): array => [$relative->member->member_code, $relative->depth])->all(),
        );
        $this->assertSame('ALICE', $this->app->make(PlacementGenealogy::class)->directParent(Member::query()->findOrFail($ids['BOB']))?->member_code);
        $this->assertSame('150', $this->app->make(VolumeTotals::class)->forMember(Member::query()->findOrFail($ids['CAROL']), 'sales')->value());
        $this->assertSame(PlanVersionStatus::Active, Plan::query()->sole()->currentActiveVersion()?->status);

        // …and extends it through the current services.
        $program = Program::query()->sole();
        $dave = $this->app->make(ProgramManager::class)->join($program, 'DAVE', CarbonImmutable::parse('2026-05-01'));
        $this->travelTo(CarbonImmutable::parse('2026-05-01 00:00:00'));
        $this->app->make(SponsorGenealogy::class)->assignSponsor($dave, Member::query()->findOrFail($ids['CAROL']));
        $this->travelBack();
        $this->record(Member::query()->findOrFail($ids['CAROL']), '50', 'order:after-upgrade', 'sales', 'order', 'ORD-2', CarbonImmutable::parse('2026-05-02'));

        $this->assertSame(3, $this->app->make(SponsorGenealogy::class)->descendants($alice)->count());
        $this->assertSame('200', $this->app->make(VolumeTotals::class)->forMember(Member::query()->findOrFail($ids['CAROL']), 'sales')->value());
    }

    /**
     * v0.1.0's data, row by row in v0.1.0's columns — never through today's
     * services, which write columns v0.1.0 did not have.
     *
     * @return array<string, string> member code => id
     */
    private function legacyRows(): array
    {
        $at = '2025-12-01 00:00:00';
        $program = strtolower((string) Str::ulid());
        $plan = strtolower((string) Str::ulid());
        $ids = [];

        DB::table('mlm_programs')->insert(['id' => $program, 'code' => 'LEGACY', 'name' => 'Legacy Program', 'created_at' => $at, 'updated_at' => $at]);

        foreach (['ALICE', 'BOB', 'CAROL'] as $code) {
            $ids[$code] = strtolower((string) Str::ulid());
            DB::table('mlm_members')->insert(['id' => $ids[$code], 'program_id' => $program, 'member_code' => $code, 'external_type' => 'user', 'external_id' => strtolower($code), 'joined_at' => $at, 'created_at' => $at, 'updated_at' => $at]);
        }

        DB::table('mlm_plans')->insert(['id' => $plan, 'program_id' => $program, 'code' => 'COMP', 'name' => 'Compensation', 'created_at' => $at, 'updated_at' => $at]);
        DB::table('mlm_plan_versions')->insert([
            'id' => strtolower((string) Str::ulid()), 'plan_id' => $plan, 'version' => 1, 'status' => 'active',
            'validated_at' => $at, 'published_at' => $at, 'activated_at' => $at, 'created_at' => $at, 'updated_at' => $at,
        ]);

        // ALICE > BOB > CAROL in both trees, with v0.1.0's closure rows.
        foreach ([['mlm_sponsor_edges', 'sponsor_id', 'assigned_at', 'sponsor'], ['mlm_placement_edges', 'parent_id', 'placed_at', 'placement']] as [$edges, $parent, $moment, $tree]) {
            DB::table($edges)->insert(['id' => strtolower((string) Str::ulid()), 'member_id' => $ids['BOB'], $parent => $ids['ALICE'], $moment => '2026-01-01 00:00:00', 'created_at' => $at, 'updated_at' => $at]);
            DB::table($edges)->insert(['id' => strtolower((string) Str::ulid()), 'member_id' => $ids['CAROL'], $parent => $ids['BOB'], $moment => '2026-01-02 00:00:00', 'created_at' => $at, 'updated_at' => $at]);

            foreach ([['ALICE', 'ALICE', 0], ['BOB', 'BOB', 0], ['CAROL', 'CAROL', 0], ['ALICE', 'BOB', 1], ['BOB', 'CAROL', 1], ['ALICE', 'CAROL', 2]] as [$ancestor, $descendant, $depth]) {
                DB::table('mlm_genealogy_paths')->insert(['tree_type' => $tree, 'ancestor_id' => $ids[$ancestor], 'descendant_id' => $ids[$descendant], 'depth' => $depth]);
            }
        }

        DB::table('mlm_volume_entries')->insert([
            'id' => strtolower((string) Str::ulid()), 'program_id' => $program, 'member_id' => $ids['CAROL'], 'type' => 'sales',
            'quantity_millionths' => 150_000_000, 'source_type' => 'order', 'source_id' => 'ORD-1', 'idempotency_key' => 'order:1',
            'effective_at' => '2026-01-10 00:00:00', 'reversal_of_id' => null, 'created_at' => $at, 'updated_at' => $at,
        ]);

        return $ids;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function snapshot(): array
    {
        $order = ['mlm_genealogy_paths' => ['tree_type', 'ancestor_id', 'descendant_id']];
        $tables = ['mlm_programs', 'mlm_members', 'mlm_plans', 'mlm_plan_versions', 'mlm_sponsor_edges', 'mlm_genealogy_paths', 'mlm_placement_edges', 'mlm_volume_entries'];
        $snapshot = [];

        foreach ($tables as $table) {
            $query = DB::table($table);

            foreach ($order[$table] ?? ['id'] as $column) {
                $query->orderBy($column);
            }

            $snapshot[$table] = $query->get()->map(static fn (object $row): array => (array) $row)->all();
        }

        return $snapshot;
    }
}
