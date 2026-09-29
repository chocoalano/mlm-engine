<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PandaBear\Mlm\Genealogy\PlacementGenealogy;
use PandaBear\Mlm\Genealogy\SponsorGenealogy;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Planning\PlanDefinitionEditor;
use PandaBear\Mlm\Planning\PlanVersionLifecycle;
use PandaBear\Mlm\Planning\Rules\MetricCondition;
use PandaBear\Mlm\Planning\Rules\RuleDefinition;
use PandaBear\Mlm\Rank\RankContext;
use PandaBear\Mlm\Rank\RankEngine;
use PandaBear\Mlm\Volume\Quantity;
use PandaBear\Mlm\Volume\RecordVolume;
use PandaBear\Mlm\Volume\VolumeRecorder;
use PHPUnit\Framework\Attributes\DataProvider;

final class MigrationTest extends TestCase
{
    private const TABLES_BEFORE_THE_LEDGER = ['mlm_programs', 'mlm_members', 'mlm_plans', 'mlm_plan_versions', 'mlm_sponsor_edges', 'mlm_genealogy_paths', 'mlm_placement_edges', 'mlm_volume_entries', 'mlm_plan_components', 'mlm_plan_rules'];

    private const TABLES = ['mlm_programs', 'mlm_members', 'mlm_plans', 'mlm_plan_versions', 'mlm_sponsor_edges', 'mlm_genealogy_paths', 'mlm_placement_edges', 'mlm_volume_entries', 'mlm_plan_components', 'mlm_plan_rules', 'mlm_wallets', 'mlm_ledger_accounts', 'mlm_ledger_transactions', 'mlm_ledger_postings'];

    public function test_migrate_creates_the_package_tables(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} is missing.");
        }
    }

    public function test_the_programs_table_has_exactly_the_minimum_columns(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            ['id', 'code', 'name', 'created_at', 'updated_at'],
            Schema::getColumnListing('mlm_programs'),
        );
    }

    public function test_the_members_table_has_exactly_the_minimum_columns(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            ['id', 'program_id', 'member_code', 'external_type', 'external_id', 'joined_at', 'created_at', 'updated_at'],
            Schema::getColumnListing('mlm_members'),
        );
    }

    public function test_the_plans_table_has_exactly_the_minimum_columns(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            ['id', 'program_id', 'code', 'name', 'created_at', 'updated_at'],
            Schema::getColumnListing('mlm_plans'),
        );
    }

    public function test_the_plan_versions_table_has_exactly_the_minimum_columns(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            [
                'id', 'plan_id', 'version', 'status',
                'validated_at', 'published_at', 'activated_at', 'superseded_at', 'archived_at',
                'created_at', 'updated_at',
            ],
            Schema::getColumnListing('mlm_plan_versions'),
        );
    }

    public function test_the_sponsor_edges_table_has_exactly_the_minimum_columns(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            ['id', 'member_id', 'sponsor_id', 'assigned_at', 'created_at', 'updated_at'],
            Schema::getColumnListing('mlm_sponsor_edges'),
        );
    }

    public function test_the_placement_edges_table_has_exactly_the_minimum_columns(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            ['id', 'member_id', 'parent_id', 'placed_at', 'created_at', 'updated_at'],
            Schema::getColumnListing('mlm_placement_edges'),
        );
    }

    public function test_the_volume_entries_table_has_exactly_the_minimum_columns(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            [
                'id', 'program_id', 'member_id', 'type', 'quantity_millionths',
                'source_type', 'source_id', 'idempotency_key', 'effective_at', 'reversal_of_id',
                'created_at', 'updated_at',
            ],
            Schema::getColumnListing('mlm_volume_entries'),
        );
    }

    public function test_volume_quantities_are_stored_as_whole_millionths(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $quantity = collect(Schema::getColumns('mlm_volume_entries'))->firstWhere('name', 'quantity_millionths');

        $this->assertIsArray($quantity);
        $this->assertStringContainsStringIgnoringCase('int', $quantity['type_name']);
        $this->assertFalse($quantity['nullable']);
    }

    public function test_a_volume_entry_is_keyed_by_its_id_and_may_reference_another_entry(): void
    {
        // The self-reference once failed on PostgreSQL because the foreign
        // key was added before the primary key. Running this on PostgreSQL
        // is the regression check.
        $this->artisan('migrate')->assertSuccessful();

        $primary = collect(Schema::getIndexes('mlm_volume_entries'))->firstWhere('primary', true);
        $reversal = collect(Schema::getForeignKeys('mlm_volume_entries'))->firstWhere('columns', ['reversal_of_id']);

        $this->assertSame(['id'], $primary['columns'] ?? null);
        $this->assertIsArray($reversal);
        $this->assertSame('mlm_volume_entries', $reversal['foreign_table']);
        $this->assertSame(['id'], $reversal['foreign_columns']);
    }

    public function test_the_volume_indexes_serve_replays_reversals_and_member_totals(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            [['program_id', 'idempotency_key'], ['reversal_of_id']],
            $this->uniqueIndexColumns('mlm_volume_entries'),
        );
        $this->assertContains(['member_id', 'type', 'effective_at'], collect(Schema::getIndexes('mlm_volume_entries'))->pluck('columns')->all());
    }

    public function test_the_genealogy_paths_table_has_exactly_the_minimum_columns(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            ['tree_type', 'ancestor_id', 'descendant_id', 'depth', 'effective_from'],
            Schema::getColumnListing('mlm_genealogy_paths'),
        );
    }

    public function test_every_genealogy_path_records_when_it_took_effect(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $column = collect(Schema::getColumns('mlm_genealogy_paths'))->firstWhere('name', 'effective_from');

        $this->assertIsArray($column);
        $this->assertFalse($column['nullable']);

        // The upgrade's scratch table never outlives the migration.
        $this->assertFalse(Schema::hasTable('mlm_genealogy_paths_replay_000009'));
    }

    public function test_the_unique_indexes_scope_identities_to_their_owner(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $this->assertSame([['code']], $this->uniqueIndexColumns('mlm_programs'));
        $this->assertEqualsCanonicalizing(
            [['program_id', 'member_code'], ['program_id', 'external_type', 'external_id']],
            $this->uniqueIndexColumns('mlm_members'),
        );
        $this->assertSame([['program_id', 'code']], $this->uniqueIndexColumns('mlm_plans'));
        $this->assertSame([['plan_id', 'version']], $this->uniqueIndexColumns('mlm_plan_versions'));
        $this->assertSame([['member_id']], $this->uniqueIndexColumns('mlm_sponsor_edges'));
        $this->assertSame([['member_id']], $this->uniqueIndexColumns('mlm_placement_edges'));
    }

    public function test_the_genealogy_indexes_serve_both_directions(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $paths = collect(Schema::getIndexes('mlm_genealogy_paths'));

        // One path per pair and tree; its prefix finds descendants.
        $this->assertSame(['tree_type', 'ancestor_id', 'descendant_id'], $paths->firstWhere('primary', true)['columns'] ?? null);

        // The reverse direction, for ancestors.
        $this->assertContains(['tree_type', 'descendant_id', 'depth'], $paths->pluck('columns')->all());

        // Descendants as of a moment: a range within one ancestor.
        $this->assertContains(['tree_type', 'ancestor_id', 'effective_from', 'depth'], $paths->pluck('columns')->all());

        // Descendants down to a depth: a range within one ancestor.
        $this->assertContains(['tree_type', 'ancestor_id', 'depth'], $paths->pluck('columns')->all());

        // Who a sponsor sponsored directly; who is placed directly under a parent.
        $this->assertContains(['sponsor_id'], collect(Schema::getIndexes('mlm_sponsor_edges'))->pluck('columns')->all());
        $this->assertContains(['parent_id'], collect(Schema::getIndexes('mlm_placement_edges'))->pluck('columns')->all());
    }

    public function test_the_placement_edge_key_is_a_ulid(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $id = collect(Schema::getColumns('mlm_placement_edges'))->firstWhere('name', 'id');

        $this->assertIsArray($id);
        $this->assertFalse($id['auto_increment']);
        $this->assertSame(['id'], collect(Schema::getIndexes('mlm_placement_edges'))->firstWhere('primary', true)['columns'] ?? null);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function ownedTables(): array
    {
        return [
            'members belong to programs' => ['mlm_members', 'program_id', 'mlm_programs'],
            'plans belong to programs' => ['mlm_plans', 'program_id', 'mlm_programs'],
            'plan versions belong to plans' => ['mlm_plan_versions', 'plan_id', 'mlm_plans'],
            'a sponsor edge names its member' => ['mlm_sponsor_edges', 'member_id', 'mlm_members'],
            'a sponsor edge names its sponsor' => ['mlm_sponsor_edges', 'sponsor_id', 'mlm_members'],
            'a path names its ancestor' => ['mlm_genealogy_paths', 'ancestor_id', 'mlm_members'],
            'a path names its descendant' => ['mlm_genealogy_paths', 'descendant_id', 'mlm_members'],
            'a placement edge names its member' => ['mlm_placement_edges', 'member_id', 'mlm_members'],
            'a placement edge names its parent' => ['mlm_placement_edges', 'parent_id', 'mlm_members'],
            'a volume entry belongs to a program' => ['mlm_volume_entries', 'program_id', 'mlm_programs'],
            'a volume entry belongs to a member' => ['mlm_volume_entries', 'member_id', 'mlm_members'],
            'a reversal names the entry it reverses' => ['mlm_volume_entries', 'reversal_of_id', 'mlm_volume_entries'],
            'a wallet belongs to a program' => ['mlm_wallets', 'program_id', 'mlm_programs'],
            'a wallet belongs to a member' => ['mlm_wallets', 'member_id', 'mlm_members'],
            'a ledger account belongs to a program' => ['mlm_ledger_accounts', 'program_id', 'mlm_programs'],
            'a wallet account names its wallet' => ['mlm_ledger_accounts', 'wallet_id', 'mlm_wallets'],
            'a ledger transaction belongs to a program' => ['mlm_ledger_transactions', 'program_id', 'mlm_programs'],
            'a ledger reversal names the transaction it reverses' => ['mlm_ledger_transactions', 'reversal_of_id', 'mlm_ledger_transactions'],
            'a posting belongs to its transaction' => ['mlm_ledger_postings', 'ledger_transaction_id', 'mlm_ledger_transactions'],
            'a posting names its account' => ['mlm_ledger_postings', 'ledger_account_id', 'mlm_ledger_accounts'],
        ];
    }

    #[DataProvider('ownedTables')]
    public function test_a_foreign_key_references_the_owner_and_restricts_its_deletion(string $table, string $column, string $owner): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $foreignKey = collect(Schema::getForeignKeys($table))->firstWhere('columns', [$column]);

        $this->assertIsArray($foreignKey, "{$table}.{$column} has no foreign key.");
        $this->assertSame($owner, $foreignKey['foreign_table']);
        $this->assertSame(['id'], $foreignKey['foreign_columns']);
        $this->assertSame('restrict', $foreignKey['on_delete']);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function ownedDefinitions(): array
    {
        return [
            'a component belongs to its plan version' => ['mlm_plan_components', 'plan_version_id', 'mlm_plan_versions'],
            'a rule belongs to its component' => ['mlm_plan_rules', 'plan_component_id', 'mlm_plan_components'],
        ];
    }

    /**
     * A definition is owned outright: it goes when its draft is deleted.
     */
    #[DataProvider('ownedDefinitions')]
    public function test_a_definition_row_references_its_owner_and_goes_with_it(string $table, string $column, string $owner): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $foreignKey = collect(Schema::getForeignKeys($table))->firstWhere('columns', [$column]);

        $this->assertIsArray($foreignKey, "{$table}.{$column} has no foreign key.");
        $this->assertSame($owner, $foreignKey['foreign_table']);
        $this->assertSame(['id'], $foreignKey['foreign_columns']);
        $this->assertSame('cascade', $foreignKey['on_delete']);
    }

    public function test_the_plan_definition_tables_have_exactly_their_columns_and_keys(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            ['id', 'plan_version_id', 'key', 'driver', 'name', 'parameters', 'position', 'created_at', 'updated_at'],
            Schema::getColumnListing('mlm_plan_components'),
        );
        $this->assertEqualsCanonicalizing(
            ['id', 'plan_component_id', 'key', 'name', 'definition', 'position', 'created_at', 'updated_at'],
            Schema::getColumnListing('mlm_plan_rules'),
        );
        $this->assertSame([['plan_version_id', 'key']], $this->uniqueIndexColumns('mlm_plan_components'));
        $this->assertSame([['plan_component_id', 'key']], $this->uniqueIndexColumns('mlm_plan_rules'));

        foreach (['mlm_plan_components', 'mlm_plan_rules'] as $table) {
            $this->assertSame(['id'], collect(Schema::getIndexes($table))->firstWhere('primary', true)['columns'] ?? null);
        }
    }

    public function test_the_financial_tables_have_exactly_their_columns_and_no_balance(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $columns = [
            'mlm_wallets' => ['id', 'program_id', 'member_id', 'currency', 'created_at', 'updated_at'],
            'mlm_ledger_accounts' => ['id', 'program_id', 'wallet_id', 'currency', 'key', 'created_at', 'updated_at'],
            'mlm_ledger_transactions' => ['id', 'program_id', 'currency', 'type', 'source_type', 'source_id', 'idempotency_key', 'occurred_at', 'reversal_of_id', 'created_at', 'updated_at'],
            'mlm_ledger_postings' => ['id', 'ledger_transaction_id', 'ledger_account_id', 'amount_millionths', 'created_at', 'updated_at'],
        ];

        foreach ($columns as $table => $expected) {
            $this->assertEqualsCanonicalizing($expected, Schema::getColumnListing($table), $table);
            $this->assertSame(['id'], collect(Schema::getIndexes($table))->firstWhere('primary', true)['columns'] ?? null, $table);
        }

        // A balance is derived from postings, never stored.
        foreach (['mlm_wallets', 'mlm_ledger_accounts'] as $table) {
            $this->assertSame([], array_values(array_filter(Schema::getColumnListing($table), static fn (string $column): bool => str_contains($column, 'balance'))), "{$table} stores a balance.");
        }
    }

    public function test_the_financial_keys_make_wallets_accounts_replays_and_reversals_unique(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $this->assertEqualsCanonicalizing([['program_id', 'member_id', 'currency']], $this->uniqueIndexColumns('mlm_wallets'));
        $this->assertEqualsCanonicalizing([['wallet_id'], ['program_id', 'currency', 'key']], $this->uniqueIndexColumns('mlm_ledger_accounts'));
        $this->assertEqualsCanonicalizing([['program_id', 'idempotency_key'], ['reversal_of_id']], $this->uniqueIndexColumns('mlm_ledger_transactions'));
        $this->assertEqualsCanonicalizing([['ledger_transaction_id', 'ledger_account_id']], $this->uniqueIndexColumns('mlm_ledger_postings'));
        $this->assertContains(['ledger_account_id'], collect(Schema::getIndexes('mlm_ledger_postings'))->pluck('columns')->all());
        $this->assertContains(['member_id'], collect(Schema::getIndexes('mlm_wallets'))->pluck('columns')->all());
    }

    public function test_every_index_and_foreign_key_name_fits_every_database(): void
    {
        // MySQL refuses names over 64 characters; PostgreSQL silently cuts
        // them at 63. SQLite keeps them whole, so this is checked here.
        $this->artisan('migrate')->assertSuccessful();

        foreach (self::TABLES as $table) {
            foreach ([...Schema::getIndexes($table), ...Schema::getForeignKeys($table)] as $key) {
                $this->assertLessThanOrEqual(63, strlen((string) ($key['name'] ?? '')), "{$table}: {$key['name']}");
            }
        }
    }

    public function test_posting_amounts_are_stored_as_whole_millionths_to_the_64_bit_limit(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $amount = collect(Schema::getColumns('mlm_ledger_postings'))->firstWhere('name', 'amount_millionths');

        $this->assertIsArray($amount);
        $this->assertStringContainsStringIgnoringCase('int', $amount['type_name']);
        $this->assertFalse($amount['nullable']);
    }

    public function test_a_database_at_000012_upgrades_to_the_ledger_without_touching_its_rows(): void
    {
        $migrations = array_map(
            static fn (string $file): string => dirname(__DIR__).'/database/migrations/'.$file,
            array_values(array_filter(scandir(dirname(__DIR__).'/database/migrations') ?: [], static fn (string $file): bool => preg_match('/_0000(0[1-9]|1[0-2])_/', $file) === 1)),
        );
        $this->assertCount(12, $migrations);
        $this->artisan('migrate', ['--path' => $migrations, '--realpath' => true])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('mlm_wallets'));

        // Everything the earlier phases write: genealogy, volume, a validated
        // plan definition with a rank ladder.
        $program = Program::factory()->create();
        [$alice, $bob] = Member::factory()->for($program)->count(2)->create()->all();
        $this->app->make(SponsorGenealogy::class)->assignSponsor($bob, $alice);
        $this->app->make(PlacementGenealogy::class)->place($bob, $alice);
        $this->app->make(VolumeRecorder::class)->record(new RecordVolume($bob, 'sales', Quantity::of('150'), 'order', 'ORD-1', 'order:ORD-1', now()));
        $version = $this->app->make(PlanVersionLifecycle::class)->draft($program->plans()->create(['code' => 'MAIN', 'name' => 'Main']));
        $ladder = $this->app->make(PlanDefinitionEditor::class)->addComponent($version, 'career-ranks', 'rank.ladder', 'Career Ranks');
        $this->app->make(PlanDefinitionEditor::class)->addRule($ladder, 'bronze', 'Bronze', RuleDefinition::all(MetricCondition::of('sponsor.network.volume', ['type' => 'sales'], '>=', '100')), 10);
        $this->app->make(PlanVersionLifecycle::class)->markValidated($version);
        $rank = $this->app->make(RankEngine::class)->evaluate($ladder, new RankContext($alice))->toArray();
        $before = $this->rowsOf(self::TABLES_BEFORE_THE_LEDGER);

        $this->artisan('migrate')->assertSuccessful();

        foreach (['mlm_wallets', 'mlm_ledger_accounts', 'mlm_ledger_transactions', 'mlm_ledger_postings'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
            $this->assertSame(0, DB::table($table)->count(), $table);
        }

        $this->assertSame($before, $this->rowsOf(self::TABLES_BEFORE_THE_LEDGER));
        $this->assertSame($rank, $this->app->make(RankEngine::class)->evaluate($ladder, new RankContext($alice))->toArray());
        $this->assertSame('bronze', $rank['selected_rank']['key'] ?? null);
    }

    public function test_a_database_at_000010_upgrades_without_touching_its_rows(): void
    {
        $migrations = array_map(
            static fn (string $file): string => dirname(__DIR__).'/database/migrations/'.$file,
            array_values(array_filter(scandir(dirname(__DIR__).'/database/migrations') ?: [], static fn (string $file): bool => preg_match('/_0000(0[1-9]|10)_/', $file) === 1)),
        );
        $this->assertCount(10, $migrations);
        $this->artisan('migrate', ['--path' => $migrations, '--realpath' => true])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('mlm_plan_components'));

        $program = Program::factory()->create();
        $plan = $program->plans()->create(['code' => 'MAIN', 'name' => 'Main']);
        $before = [
            DB::table('mlm_programs')->get()->map(static fn (object $row): array => (array) $row)->all(),
            DB::table('mlm_plans')->get()->map(static fn (object $row): array => (array) $row)->all(),
        ];

        $this->artisan('migrate')->assertSuccessful();

        $this->assertTrue(Schema::hasTable('mlm_plan_components'));
        $this->assertTrue(Schema::hasTable('mlm_plan_rules'));
        $this->assertSame(0, DB::table('mlm_plan_components')->count());
        $this->assertSame($before, [
            DB::table('mlm_programs')->get()->map(static fn (object $row): array => (array) $row)->all(),
            DB::table('mlm_plans')->get()->map(static fn (object $row): array => (array) $row)->all(),
        ]);
        $this->assertTrue($plan->is(Plan::query()->sole()));
    }

    public function test_the_plan_version_status_defaults_to_draft(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $status = collect(Schema::getColumns('mlm_plan_versions'))->firstWhere('name', 'status');

        $this->assertIsArray($status);
        $this->assertStringContainsString('draft', (string) $status['default']);
    }

    public function test_rollback_drops_the_package_tables(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $this->artisan('migrate:rollback', [
            '--path' => dirname(__DIR__).'/database/migrations',
            '--realpath' => true,
        ])->assertSuccessful();

        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} survived the rollback.");
        }
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, list<array<string, mixed>>>
     */
    private function rowsOf(array $tables): array
    {
        $rows = [];

        foreach ($tables as $table) {
            $rows[$table] = DB::table($table)->get()->map(static fn (object $row): array => (array) $row)->sortBy(static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR))->values()->all();
        }

        return $rows;
    }

    /**
     * Unique, non-primary indexes, as column lists.
     *
     * @return list<list<string>>
     */
    private function uniqueIndexColumns(string $table): array
    {
        $unique = array_filter(
            Schema::getIndexes($table),
            static fn (array $index): bool => $index['unique'] && ! $index['primary'],
        );

        return array_values(array_map(static fn (array $index): array => $index['columns'], $unique));
    }
}
