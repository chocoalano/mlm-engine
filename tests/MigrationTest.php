<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PandaBear\Mlm\Binary\BinaryPlacementManager;
use PandaBear\Mlm\Binary\BinarySide;
use PandaBear\Mlm\Calculation\CalculationContext;
use PandaBear\Mlm\Calculation\CalculationEngine;
use PandaBear\Mlm\Commission\CommissionAdjustmentEngine;
use PandaBear\Mlm\Commission\CommissionAdjustmentOutcome;
use PandaBear\Mlm\Commission\CommissionLifecycle;
use PandaBear\Mlm\Commission\CommissionPoster;
use PandaBear\Mlm\Finance\LedgerAccountManager;
use PandaBear\Mlm\Finance\LedgerPostingInput;
use PandaBear\Mlm\Finance\LedgerRecorder;
use PandaBear\Mlm\Finance\PostLedgerTransaction;
use PandaBear\Mlm\Finance\WalletManager;
use PandaBear\Mlm\Genealogy\PlacementGenealogy;
use PandaBear\Mlm\Genealogy\SponsorGenealogy;
use PandaBear\Mlm\Matrix\MatrixNetworkManager;
use PandaBear\Mlm\Matrix\MatrixPlacementManager;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\PlacementEdge;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanComponent;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Models\VolumeEntry;
use PandaBear\Mlm\Planning\PlanDefinitionEditor;
use PandaBear\Mlm\Planning\PlanVersionLifecycle;
use PandaBear\Mlm\Planning\Rules\MetricCondition;
use PandaBear\Mlm\Planning\Rules\RuleDefinition;
use PandaBear\Mlm\Rank\RankContext;
use PandaBear\Mlm\Rank\RankEngine;
use PandaBear\Mlm\Volume\Quantity;
use PandaBear\Mlm\Volume\RecordVolume;
use PandaBear\Mlm\Volume\ReverseVolume;
use PandaBear\Mlm\Volume\VolumeRecorder;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class MigrationTest extends TestCase
{
    private const TABLES_BEFORE_THE_LEDGER = ['mlm_programs', 'mlm_members', 'mlm_plans', 'mlm_plan_versions', 'mlm_sponsor_edges', 'mlm_genealogy_paths', 'mlm_placement_edges', 'mlm_volume_entries', 'mlm_plan_components', 'mlm_plan_rules'];

    private const TABLES = ['mlm_programs', 'mlm_members', 'mlm_plans', 'mlm_plan_versions', 'mlm_sponsor_edges', 'mlm_genealogy_paths', 'mlm_placement_edges', 'mlm_volume_entries', 'mlm_plan_components', 'mlm_plan_rules', 'mlm_wallets', 'mlm_ledger_accounts', 'mlm_ledger_transactions', 'mlm_ledger_postings', 'mlm_calculation_runs', 'mlm_commissions', 'mlm_commission_adjustments', 'mlm_binary_placement_positions', 'mlm_binary_pairing_cursors', 'mlm_binary_carry_lots', 'mlm_binary_pairing_results', 'mlm_binary_pairing_allocations', 'mlm_binary_pairing_corrections', 'mlm_binary_pairing_restorations', 'mlm_matrix_networks', 'mlm_matrix_placement_positions', 'mlm_calculation_batches', 'mlm_calculation_batch_items', 'mlm_commission_periods', 'mlm_commission_period_runs', 'mlm_payout_requests', 'mlm_payout_batches', 'mlm_payout_batch_items'];

    private const PAYOUT_TABLES = ['mlm_payout_requests', 'mlm_payout_batches', 'mlm_payout_batch_items'];

    private const PERIOD_TABLES = ['mlm_commission_periods', 'mlm_commission_period_runs'];

    private const MATRIX_TABLES = ['mlm_matrix_networks', 'mlm_matrix_placement_positions'];

    private const BATCH_TABLES = ['mlm_calculation_batches', 'mlm_calculation_batch_items'];

    private const PAIRING_TABLES = ['mlm_binary_pairing_cursors', 'mlm_binary_carry_lots', 'mlm_binary_pairing_results', 'mlm_binary_pairing_allocations', 'mlm_binary_pairing_corrections', 'mlm_binary_pairing_restorations'];

    private const BUILT_IN_STRATEGIES = ['direct-sponsor.fixed', 'direct-sponsor.proportional', 'unilevel.fixed', 'unilevel.proportional'];

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
            'a run belongs to a program' => ['mlm_calculation_runs', 'program_id', 'mlm_programs'],
            'a run names its plan version' => ['mlm_calculation_runs', 'plan_version_id', 'mlm_plan_versions'],
            'a run names its component' => ['mlm_calculation_runs', 'plan_component_id', 'mlm_plan_components'],
            'a run names its source account' => ['mlm_calculation_runs', 'source_ledger_account_id', 'mlm_ledger_accounts'],
            'a commission belongs to its run' => ['mlm_commissions', 'calculation_run_id', 'mlm_calculation_runs'],
            'a commission belongs to a program' => ['mlm_commissions', 'program_id', 'mlm_programs'],
            'a commission belongs to a member' => ['mlm_commissions', 'member_id', 'mlm_members'],
            'a commission names its ledger transaction' => ['mlm_commissions', 'ledger_transaction_id', 'mlm_ledger_transactions'],
            'a commission names its reversal' => ['mlm_commissions', 'reversal_ledger_transaction_id', 'mlm_ledger_transactions'],
            'an adjustment belongs to a program' => ['mlm_commission_adjustments', 'program_id', 'mlm_programs'],
            'an adjustment corrects a commission' => ['mlm_commission_adjustments', 'commission_id', 'mlm_commissions'],
            'an adjustment names its ledger reversal' => ['mlm_commission_adjustments', 'ledger_transaction_id', 'mlm_ledger_transactions'],
            'a binary position enrols a placement edge' => ['mlm_binary_placement_positions', 'placement_edge_id', 'mlm_placement_edges'],
            'a binary position names its parent' => ['mlm_binary_placement_positions', 'parent_id', 'mlm_members'],
            'a matrix network belongs to a program' => ['mlm_matrix_networks', 'program_id', 'mlm_programs'],
            'a matrix position belongs to its network' => ['mlm_matrix_placement_positions', 'matrix_network_id', 'mlm_matrix_networks'],
            'a matrix position enrols a placement edge' => ['mlm_matrix_placement_positions', 'placement_edge_id', 'mlm_placement_edges'],
            'a matrix position names its parent' => ['mlm_matrix_placement_positions', 'parent_id', 'mlm_members'],
            'a calculation batch belongs to a program' => ['mlm_calculation_batches', 'program_id', 'mlm_programs'],
            'a calculation batch composes a plan version' => ['mlm_calculation_batches', 'plan_version_id', 'mlm_plan_versions'],
            'a calculation batch is funded from a ledger account' => ['mlm_calculation_batches', 'source_ledger_account_id', 'mlm_ledger_accounts'],
            'a batch item belongs to its batch' => ['mlm_calculation_batch_items', 'calculation_batch_id', 'mlm_calculation_batches'],
            'a batch item is one plan component' => ['mlm_calculation_batch_items', 'plan_component_id', 'mlm_plan_components'],
            'a batch item links its calculation run' => ['mlm_calculation_batch_items', 'calculation_run_id', 'mlm_calculation_runs'],
            'a commission period belongs to a program' => ['mlm_commission_periods', 'program_id', 'mlm_programs'],
            'a commission period keeps its plan version' => ['mlm_commission_periods', 'plan_version_id', 'mlm_plan_versions'],
            'a commission period is funded from a ledger account' => ['mlm_commission_periods', 'source_ledger_account_id', 'mlm_ledger_accounts'],
            'a period run belongs to its period' => ['mlm_commission_period_runs', 'commission_period_id', 'mlm_commission_periods'],
            'a period run is one plan component' => ['mlm_commission_period_runs', 'plan_component_id', 'mlm_plan_components'],
            'a period run links its calculation run' => ['mlm_commission_period_runs', 'calculation_run_id', 'mlm_calculation_runs'],
            'a payout request belongs to a program' => ['mlm_payout_requests', 'program_id', 'mlm_programs'],
            'a payout request belongs to a member' => ['mlm_payout_requests', 'member_id', 'mlm_members'],
            'a payout request spends a wallet' => ['mlm_payout_requests', 'wallet_id', 'mlm_wallets'],
            'a payout request settles through a ledger account' => ['mlm_payout_requests', 'settlement_ledger_account_id', 'mlm_ledger_accounts'],
            'a payout request names its reservation' => ['mlm_payout_requests', 'reservation_ledger_transaction_id', 'mlm_ledger_transactions'],
            'a payout request names its refund' => ['mlm_payout_requests', 'refund_ledger_transaction_id', 'mlm_ledger_transactions'],
            'a payout batch belongs to a program' => ['mlm_payout_batches', 'program_id', 'mlm_programs'],
            'a payout batch item belongs to its batch' => ['mlm_payout_batch_items', 'payout_batch_id', 'mlm_payout_batches'],
            'a payout batch item is one request' => ['mlm_payout_batch_items', 'payout_request_id', 'mlm_payout_requests'],
            'a pairing cursor belongs to a program' => ['mlm_binary_pairing_cursors', 'program_id', 'mlm_programs'],
            'a pairing cursor belongs to its component' => ['mlm_binary_pairing_cursors', 'plan_component_id', 'mlm_plan_components'],
            'a pairing cursor names its last run' => ['mlm_binary_pairing_cursors', 'last_calculation_run_id', 'mlm_calculation_runs'],
            'a carry lot belongs to a program' => ['mlm_binary_carry_lots', 'program_id', 'mlm_programs'],
            'a carry lot belongs to its component' => ['mlm_binary_carry_lots', 'plan_component_id', 'mlm_plan_components'],
            'a carry lot belongs to its binary member' => ['mlm_binary_carry_lots', 'member_id', 'mlm_members'],
            'a carry lot names its source entry' => ['mlm_binary_carry_lots', 'source_volume_entry_id', 'mlm_volume_entries'],
            'a carry lot names the reversal that took it back' => ['mlm_binary_carry_lots', 'reversed_by_volume_entry_id', 'mlm_volume_entries'],
            'a pairing result belongs to its run' => ['mlm_binary_pairing_results', 'calculation_run_id', 'mlm_calculation_runs'],
            'a pairing result belongs to a program' => ['mlm_binary_pairing_results', 'program_id', 'mlm_programs'],
            'a pairing result belongs to its component' => ['mlm_binary_pairing_results', 'plan_component_id', 'mlm_plan_components'],
            'a pairing result belongs to its binary member' => ['mlm_binary_pairing_results', 'member_id', 'mlm_members'],
            'a pairing result names its commission' => ['mlm_binary_pairing_results', 'commission_id', 'mlm_commissions'],
            'an allocation belongs to its result' => ['mlm_binary_pairing_allocations', 'binary_pairing_result_id', 'mlm_binary_pairing_results'],
            'an allocation draws on a carry lot' => ['mlm_binary_pairing_allocations', 'binary_carry_lot_id', 'mlm_binary_carry_lots'],
            'a correction belongs to a program' => ['mlm_binary_pairing_corrections', 'program_id', 'mlm_programs'],
            'a correction belongs to its component' => ['mlm_binary_pairing_corrections', 'plan_component_id', 'mlm_plan_components'],
            'a correction names the run that made it' => ['mlm_binary_pairing_corrections', 'calculation_run_id', 'mlm_calculation_runs'],
            'a correction names its reversal' => ['mlm_binary_pairing_corrections', 'reversal_volume_entry_id', 'mlm_volume_entries'],
            'a correction names the reversed original' => ['mlm_binary_pairing_corrections', 'original_volume_entry_id', 'mlm_volume_entries'],
            'a correction names the pairing it undoes' => ['mlm_binary_pairing_corrections', 'binary_pairing_result_id', 'mlm_binary_pairing_results'],
            'a correction names the allocation it invalidates' => ['mlm_binary_pairing_corrections', 'invalidated_allocation_id', 'mlm_binary_pairing_allocations'],
            'a correction names the binary member' => ['mlm_binary_pairing_corrections', 'member_id', 'mlm_members'],
            'a correction names the commission earned' => ['mlm_binary_pairing_corrections', 'commission_id', 'mlm_commissions'],
            'a restoration belongs to its correction' => ['mlm_binary_pairing_restorations', 'binary_pairing_correction_id', 'mlm_binary_pairing_corrections'],
            'a restoration names the allocation it gives back' => ['mlm_binary_pairing_restorations', 'restored_allocation_id', 'mlm_binary_pairing_allocations'],
            'a restoration names the lot it refills' => ['mlm_binary_pairing_restorations', 'binary_carry_lot_id', 'mlm_binary_carry_lots'],
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

    public function test_the_calculation_tables_have_exactly_their_columns_and_keys(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            ['id', 'program_id', 'plan_version_id', 'plan_component_id', 'strategy', 'currency', 'source_ledger_account_id', 'from_at', 'until_at', 'idempotency_key', 'created_at', 'updated_at'],
            Schema::getColumnListing('mlm_calculation_runs'),
        );
        $this->assertEqualsCanonicalizing(
            [
                'id', 'calculation_run_id', 'program_id', 'member_id', 'candidate_key', 'currency', 'amount_millionths', 'earned_at', 'trace',
                'status', 'pending_at', 'approved_at', 'posted_at', 'cancelled_at', 'reversed_at', 'source_type', 'source_id',
                'ledger_transaction_id', 'reversal_ledger_transaction_id', 'posted_amount_millionths', 'held_at', 'available_at', 'created_at', 'updated_at',
            ],
            Schema::getColumnListing('mlm_commissions'),
        );
        $this->assertEqualsCanonicalizing([['program_id', 'idempotency_key']], $this->uniqueIndexColumns('mlm_calculation_runs'));
        $this->assertEqualsCanonicalizing([['calculation_run_id', 'candidate_key'], ['ledger_transaction_id'], ['reversal_ledger_transaction_id']], $this->uniqueIndexColumns('mlm_commissions'));

        foreach (['mlm_calculation_runs', 'mlm_commissions'] as $table) {
            $this->assertSame(['id'], collect(Schema::getIndexes($table))->firstWhere('primary', true)['columns'] ?? null, $table);
        }

        $amount = collect(Schema::getColumns('mlm_commissions'))->firstWhere('name', 'amount_millionths');
        $this->assertIsArray($amount);
        $this->assertStringContainsStringIgnoringCase('int', $amount['type_name']);

        $posted = collect(Schema::getColumns('mlm_commissions'))->firstWhere('name', 'posted_amount_millionths');
        $this->assertIsArray($posted);
        $this->assertStringContainsStringIgnoringCase('int', $posted['type_name']);
        $this->assertTrue($posted['nullable']);
    }

    public function test_a_database_at_000016_upgrades_to_calculation_runs_without_touching_its_rows(): void
    {
        $migrations = array_map(
            static fn (string $file): string => dirname(__DIR__).'/database/migrations/'.$file,
            array_values(array_filter(scandir(dirname(__DIR__).'/database/migrations') ?: [], static fn (string $file): bool => preg_match('/_0000(0[1-9]|1[0-6])_/', $file) === 1)),
        );
        $this->assertCount(16, $migrations);
        // With the period table volume recording reads, as this release does.
        $migrations[] = $this->migration('000035');
        $this->artisan('migrate', ['--path' => $migrations, '--realpath' => true])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('mlm_calculation_runs'));

        // Everything the earlier phases write, the ledger included.
        $program = Program::factory()->create();
        [$alice, $bob] = Member::factory()->for($program)->count(2)->create()->all();
        $this->app->make(SponsorGenealogy::class)->assignSponsor($bob, $alice);
        $this->app->make(VolumeRecorder::class)->record(new RecordVolume($bob, 'sales', Quantity::of('150'), 'order', 'ORD-1', 'order:ORD-1', now()));
        $version = $this->app->make(PlanVersionLifecycle::class)->draft($program->plans()->create(['code' => 'MAIN', 'name' => 'Main']));
        $ladder = $this->app->make(PlanDefinitionEditor::class)->addComponent($version, 'career-ranks', 'rank.ladder', 'Career Ranks');
        $this->app->make(PlanDefinitionEditor::class)->addRule($ladder, 'bronze', 'Bronze', RuleDefinition::all(MetricCondition::of('member.volume', ['type' => 'sales'], '>=', '100')), 10);
        $this->app->make(PlanVersionLifecycle::class)->markValidated($version);
        $clearing = $this->app->make(LedgerAccountManager::class)->openSystemAccount($program, 'IDR', 'adjustment.clearing');
        $wallet = $this->app->make(WalletManager::class)->open($alice, 'IDR');
        $this->app->make(LedgerRecorder::class)->post(new PostLedgerTransaction($program, 'IDR', 'adjustment', 'manual', 'ADJ-1', 'adjustment:ADJ-1', now(), [
            LedgerPostingInput::of($clearing, '-100'),
            LedgerPostingInput::of(LedgerAccount::query()->where('wallet_id', $wallet->id)->sole(), '100'),
        ]));
        $tables = [...self::TABLES_BEFORE_THE_LEDGER, 'mlm_wallets', 'mlm_ledger_accounts', 'mlm_ledger_transactions', 'mlm_ledger_postings'];
        $before = $this->rowsOf($tables);

        $this->artisan('migrate')->assertSuccessful();

        foreach (['mlm_calculation_runs', 'mlm_commissions'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
            $this->assertSame(0, DB::table($table)->count(), $table);
        }

        $this->assertSame($before, $this->rowsOf($tables));
    }

    public function test_commission_strategies_find_their_source_entries_by_an_index(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $this->assertContains(
            ['program_id', 'type', 'source_type', 'effective_at'],
            collect(Schema::getIndexes('mlm_volume_entries'))->pluck('columns')->all(),
        );
    }

    public function test_commissions_carry_optional_provenance_and_adjustments_their_own_table(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $this->assertContains(['source_type', 'source_id', 'program_id'], collect(Schema::getIndexes('mlm_commissions'))->pluck('columns')->all());
        $this->assertEqualsCanonicalizing(
            ['id', 'program_id', 'commission_id', 'type', 'source_type', 'source_id', 'amount_millionths', 'occurred_at', 'outcome', 'ledger_transaction_id', 'trace', 'created_at', 'updated_at'],
            Schema::getColumnListing('mlm_commission_adjustments'),
        );
        $this->assertEqualsCanonicalizing([['commission_id', 'type', 'source_type', 'source_id'], ['ledger_transaction_id']], $this->uniqueIndexColumns('mlm_commission_adjustments'));

        foreach (['source_type', 'source_id'] as $column) {
            $this->assertTrue(collect(Schema::getColumns('mlm_commissions'))->firstWhere('name', $column)['nullable'] ?? false, $column);
        }
    }

    public function test_a_database_at_000019_gains_provenance_for_the_built_in_strategies_only(): void
    {
        [$entry, $commissions] = $this->legacyCommissions();
        $before = $this->rowsOf(['mlm_programs', 'mlm_members', 'mlm_volume_entries', 'mlm_plan_versions', 'mlm_plan_components', 'mlm_calculation_runs', 'mlm_ledger_accounts']);
        $commissionsBefore = $this->rowsOf(['mlm_commissions'])['mlm_commissions'];

        $this->artisan('migrate')->assertSuccessful();

        foreach ($commissions as $strategy => $id) {
            $row = DB::table('mlm_commissions')->where('id', $id)->first();
            $this->assertSame(
                in_array($strategy, self::BUILT_IN_STRATEGIES, true) ? ['volume-entry', $entry->id] : [null, null],
                [$row?->source_type, $row?->source_id],
                $strategy,
            );
        }

        // Nothing else of any commission, nor any other row, changed; none
        // was posted, so none records a posted amount.
        $this->assertSame($commissionsBefore, array_map(
            static fn (array $row): array => array_diff_key($row, ['source_type' => 1, 'source_id' => 1, 'posted_amount_millionths' => 1, 'held_at' => 1, 'available_at' => 1]),
            $this->rowsOf(['mlm_commissions'])['mlm_commissions'],
        ));
        $this->assertSame(0, DB::table('mlm_commissions')->whereNotNull('posted_amount_millionths')->count());
        $this->assertSame($before, $this->rowsOf(array_keys($before)));

        // The backfilled provenance is what a later reversal is found by.
        $reversal = $this->app->make(VolumeRecorder::class)->reverse(new ReverseVolume($entry, 'refund', 'RF-1', 'refund:RF-1', now()->addDay()));
        $result = $this->app->make(CommissionAdjustmentEngine::class)->processVolumeReversal($reversal);

        $this->assertSame(4, $result->count(CommissionAdjustmentOutcome::Cancelled));
        $this->assertSame('calculated', DB::table('mlm_commissions')->where('id', $commissions['acme.custom'])->value('status'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unprovableTraces(): array
    {
        return [
            'a trace without a source' => ['{"strategy":"direct-sponsor.fixed"}'],
            'a trace that is a list' => ['[1,2]'],
            'a padded source id' => ['{"source":{"volume_entry_id":" 01padded"}}'],
            'a numeric source id' => ['{"source":{"volume_entry_id":42}}'],
            'a missing entry' => ['{"source":{"volume_entry_id":"01missingentry00000000000000"}}'],
        ];
    }

    /**
     * A built-in strategy's commission whose provenance cannot be proven
     * stops the migration, writes none, and a rerun completes it once the
     * row is corrected.
     */
    #[DataProvider('unprovableTraces')]
    public function test_provenance_that_cannot_be_proven_stops_the_migration_and_a_rerun_completes_it(string $trace): void
    {
        [$entry, $commissions] = $this->legacyCommissions();
        $good = DB::table('mlm_commissions')->where('id', $commissions['direct-sponsor.fixed'])->value('trace');
        DB::table('mlm_commissions')->where('id', $commissions['unilevel.fixed'])->update(['trace' => $trace]);

        try {
            Artisan::call('migrate');
            $this->fail('The migration backfilled unprovable provenance.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString("Commission [{$commissions['unilevel.fixed']}] cannot be given its source provenance", $exception->getMessage());
        }

        $this->assertSame(0, DB::table('migrations')->where('migration', 'like', '%000020%')->count());

        if (Schema::hasColumn('mlm_commissions', 'source_type')) {
            // MySQL keeps the columns of a failed run; the rows stay empty.
            $this->assertSame(0, DB::table('mlm_commissions')->whereNotNull('source_type')->count());
        }

        DB::table('mlm_commissions')->where('id', $commissions['unilevel.fixed'])->update(['trace' => $good]);
        $this->artisan('migrate')->assertSuccessful();

        $this->assertSame(4, DB::table('mlm_commissions')->where('source_type', 'volume-entry')->where('source_id', $entry->id)->count());
    }

    /**
     * @return array<string, array{bool, string}>
     */
    public static function misplacedSources(): array
    {
        return [
            'an entry of another program' => [true, "not the commission's program"],
            'a reversal instead of an original' => [false, 'which is a reversal, not an original entry'],
        ];
    }

    #[DataProvider('misplacedSources')]
    public function test_provenance_naming_another_programs_entry_or_a_reversal_stops_the_migration(bool $otherProgram, string $reason): void
    {
        [$entry, $commissions] = $this->legacyCommissions();
        $named = $otherProgram
            ? $this->app->make(VolumeRecorder::class)->record(new RecordVolume(Member::factory()->create(), 'sales', Quantity::of('5'), 'order', 'ORD-X', 'order:ORD-X', now()))
            : $this->app->make(VolumeRecorder::class)->reverse(new ReverseVolume($entry, 'refund', 'RF-1', 'refund:RF-1', now()->addDay()));
        DB::table('mlm_commissions')->where('id', $commissions['unilevel.proportional'])->update(['trace' => json_encode(['source' => ['volume_entry_id' => $named->id]])]);

        try {
            Artisan::call('migrate');
            $this->fail('Misplaced provenance was backfilled.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($reason, $exception->getMessage());
        }

        $this->assertSame(0, DB::table('migrations')->where('migration', 'like', '%000020%')->count());
    }

    public function test_provenance_and_adjustments_roll_back_to_000019_keeping_every_row_they_found(): void
    {
        [$entry, $commissions] = $this->legacyCommissions();
        $bob = Member::query()->findOrFail($entry->member_id);
        $alice = Member::query()->whereKeyNot($bob->id)->sole();
        $this->app->make(SponsorGenealogy::class)->assignSponsor($bob, $alice);
        $this->app->make(PlacementGenealogy::class)->place($bob, $alice);
        $schema = $this->schemaOf();
        $rows = $this->rowsOf([...self::TABLES_BEFORE_THE_LEDGER, 'mlm_wallets', 'mlm_ledger_accounts', 'mlm_ledger_transactions', 'mlm_ledger_postings', 'mlm_calculation_runs', 'mlm_commissions']);

        $this->artisan('migrate', ['--path' => $this->migration('000020'), '--realpath' => true])->assertSuccessful();
        $this->artisan('migrate', ['--path' => $this->migration('000021'), '--realpath' => true])->assertSuccessful();

        foreach ($commissions as $strategy => $id) {
            $this->assertSame(
                in_array($strategy, self::BUILT_IN_STRATEGIES, true) ? ['volume-entry', $entry->id] : [null, null],
                array_values((array) DB::table('mlm_commissions')->where('id', $id)->first(['source_type', 'source_id'])),
                $strategy,
            );
        }

        $this->assertTrue(Schema::hasTable('mlm_commission_adjustments'));

        // One step at a time, newest first, as a deployment would undo them.
        $this->artisan('migrate:rollback', ['--path' => $this->migration('000021'), '--realpath' => true])->assertSuccessful();

        $this->assertFalse(Schema::hasTable('mlm_commission_adjustments'));
        $this->assertTrue(Schema::hasColumn('mlm_commissions', 'source_type'));

        $this->artisan('migrate:rollback', ['--path' => $this->migration('000020'), '--realpath' => true])->assertSuccessful();

        $this->assertFalse(Schema::hasColumn('mlm_commissions', 'source_type'));
        $this->assertFalse(Schema::hasColumn('mlm_commissions', 'source_id'));
        $this->assertSame($rows, $this->rowsOf(array_keys($rows)));
        $this->assertSame($schema, $this->schemaOf());
        $this->assertSame(20, DB::table('migrations')->count());
    }

    public function test_binary_positions_have_exactly_their_columns_and_one_child_per_side(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            ['id', 'placement_edge_id', 'parent_id', 'side', 'assigned_at', 'created_at', 'updated_at'],
            Schema::getColumnListing('mlm_binary_placement_positions'),
        );
        $this->assertEqualsCanonicalizing([['placement_edge_id'], ['parent_id', 'side']], $this->uniqueIndexColumns('mlm_binary_placement_positions'));
        $this->assertSame(['id'], collect(Schema::getIndexes('mlm_binary_placement_positions'))->firstWhere('primary', true)['columns'] ?? null);

        // The generic placement stays positionless.
        $this->assertEqualsCanonicalizing(['id', 'member_id', 'parent_id', 'placed_at', 'created_at', 'updated_at'], Schema::getColumnListing('mlm_placement_edges'));

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->assertSame('utf8mb4_bin', collect(Schema::getColumns('mlm_binary_placement_positions'))->firstWhere('name', 'side')['collation'] ?? null);
        }
    }

    public function test_binary_pairing_state_has_exactly_its_columns_and_keys(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            ['id', 'program_id', 'plan_component_id', 'started_at', 'through_at', 'last_calculation_run_id', 'created_at', 'updated_at'],
            Schema::getColumnListing('mlm_binary_pairing_cursors'),
        );
        $this->assertEqualsCanonicalizing(
            ['id', 'program_id', 'plan_component_id', 'member_id', 'side', 'source_volume_entry_id', 'source_effective_at', 'quantity_millionths', 'remaining_millionths', 'reversed_by_volume_entry_id', 'created_at', 'updated_at'],
            Schema::getColumnListing('mlm_binary_carry_lots'),
        );
        $this->assertEqualsCanonicalizing(
            [
                'id', 'calculation_run_id', 'program_id', 'plan_component_id', 'member_id',
                'left_carry_before', 'right_carry_before', 'left_added', 'right_added', 'left_restored', 'right_restored', 'left_reversed', 'right_reversed', 'left_available', 'right_available',
                'pair_quantity', 'pair_count', 'consumed_quantity', 'left_carry_after', 'right_carry_after', 'commission_id', 'created_at', 'updated_at',
            ],
            Schema::getColumnListing('mlm_binary_pairing_results'),
        );
        $this->assertEqualsCanonicalizing(
            ['id', 'binary_pairing_result_id', 'binary_carry_lot_id', 'side', 'quantity_millionths', 'created_at', 'updated_at'],
            Schema::getColumnListing('mlm_binary_pairing_allocations'),
        );

        $this->assertSame([['plan_component_id']], $this->uniqueIndexColumns('mlm_binary_pairing_cursors'));
        $this->assertSame([['plan_component_id', 'member_id', 'source_volume_entry_id']], $this->uniqueIndexColumns('mlm_binary_carry_lots'));
        $this->assertEqualsCanonicalizing([['calculation_run_id', 'member_id'], ['commission_id']], $this->uniqueIndexColumns('mlm_binary_pairing_results'));
        $this->assertSame([['binary_pairing_result_id', 'binary_carry_lot_id']], $this->uniqueIndexColumns('mlm_binary_pairing_allocations'));

        // Open carry, by member side and by component; a source entry's lots; a lot's draws.
        $this->assertContains(['plan_component_id', 'member_id', 'side', 'remaining_millionths'], collect(Schema::getIndexes('mlm_binary_carry_lots'))->pluck('columns')->all());
        $this->assertContains(['plan_component_id', 'remaining_millionths', 'member_id', 'side', 'program_id'], collect(Schema::getIndexes('mlm_binary_carry_lots'))->pluck('columns')->all());
        $this->assertContains(['source_volume_entry_id'], collect(Schema::getIndexes('mlm_binary_carry_lots'))->pluck('columns')->all());
        $this->assertContains(['binary_carry_lot_id'], collect(Schema::getIndexes('mlm_binary_pairing_allocations'))->pluck('columns')->all());

        // One entry's quantity in an integer column; sums in exact text.
        foreach (['mlm_binary_carry_lots' => ['quantity_millionths', 'remaining_millionths'], 'mlm_binary_pairing_allocations' => ['quantity_millionths']] as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertStringContainsStringIgnoringCase('int', collect(Schema::getColumns($table))->firstWhere('name', $column)['type_name'] ?? '', "{$table}.{$column}");
            }
        }

        foreach (['left_carry_before', 'pair_count', 'consumed_quantity', 'right_carry_after'] as $column) {
            $this->assertStringContainsStringIgnoringCase('text', collect(Schema::getColumns('mlm_binary_pairing_results'))->firstWhere('name', $column)['type_name'] ?? '', $column);
        }

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            foreach (['mlm_binary_carry_lots', 'mlm_binary_pairing_allocations'] as $table) {
                $this->assertSame('utf8mb4_bin', collect(Schema::getColumns($table))->firstWhere('name', 'side')['collation'] ?? null, $table);
            }
        }
    }

    public function test_the_correction_journal_has_exactly_its_columns_and_keys(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            ['id', 'program_id', 'plan_component_id', 'calculation_run_id', 'reversal_volume_entry_id', 'original_volume_entry_id', 'binary_pairing_result_id', 'invalidated_allocation_id', 'member_id', 'invalidated_side', 'quantity_millionths', 'commission_id', 'created_at', 'updated_at'],
            Schema::getColumnListing('mlm_binary_pairing_corrections'),
        );
        $this->assertEqualsCanonicalizing(
            ['id', 'binary_pairing_correction_id', 'restored_allocation_id', 'binary_carry_lot_id', 'side', 'quantity_millionths', 'created_at', 'updated_at'],
            Schema::getColumnListing('mlm_binary_pairing_restorations'),
        );
        $this->assertSame([['reversal_volume_entry_id', 'invalidated_allocation_id']], $this->uniqueIndexColumns('mlm_binary_pairing_corrections'));
        $this->assertSame([['binary_pairing_correction_id', 'restored_allocation_id']], $this->uniqueIndexColumns('mlm_binary_pairing_restorations'));

        foreach (['mlm_binary_pairing_corrections' => [['original_volume_entry_id'], ['binary_pairing_result_id'], ['invalidated_allocation_id'], ['commission_id']], 'mlm_binary_pairing_restorations' => [['restored_allocation_id'], ['binary_carry_lot_id']]] as $table => $indexes) {
            foreach ($indexes as $columns) {
                $this->assertContains($columns, collect(Schema::getIndexes($table))->pluck('columns')->all(), $table);
            }
        }

        foreach (['left_restored', 'right_restored'] as $column) {
            $restored = collect(Schema::getColumns('mlm_binary_pairing_results'))->firstWhere('name', $column);
            $this->assertIsArray($restored);
            $this->assertStringContainsStringIgnoringCase('text', $restored['type_name']);
            $this->assertFalse($restored['nullable'], $column);
        }
    }

    public function test_a_database_at_000026_gains_restored_carry_and_the_correction_journal_and_can_lose_them_again(): void
    {
        // A binary pairing run written as 000026 left it: then the
        // correction migrations are undone, and done again.
        $this->artisan('migrate')->assertSuccessful();
        $this->pairingRun();
        $corrections = [$this->migration('000027'), $this->migration('000028'), $this->migration('000029')];
        $this->artisan('migrate:rollback', ['--path' => $corrections, '--realpath' => true])->assertSuccessful();

        $this->assertFalse(Schema::hasColumn('mlm_binary_pairing_results', 'left_restored'));
        $this->assertFalse(Schema::hasTable('mlm_binary_pairing_corrections'));
        $this->assertSame(1, DB::table('mlm_binary_pairing_results')->count());
        $tables = array_values(array_diff(self::TABLES, ['mlm_binary_pairing_corrections', 'mlm_binary_pairing_restorations']));
        $schema = $this->schemaOf();
        $rows = $this->rowsOf($tables);

        $this->artisan('migrate', ['--path' => $corrections, '--realpath' => true])->assertSuccessful();

        // Nothing was restored before corrections existed.
        $this->assertSame([['0', '0']], DB::table('mlm_binary_pairing_results')->get(['left_restored', 'right_restored'])->map(static fn (object $row): array => [$row->left_restored, $row->right_restored])->all());
        $this->assertSame([0, 0], [DB::table('mlm_binary_pairing_corrections')->count(), DB::table('mlm_binary_pairing_restorations')->count()]);
        $this->assertSame($rows['mlm_binary_pairing_results'], array_map(static fn (array $row): array => array_diff_key($row, ['left_restored' => 1, 'right_restored' => 1]), $this->rowsOf(['mlm_binary_pairing_results'])['mlm_binary_pairing_results']));
        $this->assertSame(array_diff_key($rows, ['mlm_binary_pairing_results' => 1]), array_diff_key($this->rowsOf($tables), ['mlm_binary_pairing_results' => 1]));

        $this->artisan('migrate:rollback', ['--path' => $corrections, '--realpath' => true])->assertSuccessful();

        $this->assertSame($schema, $this->schemaOf());
    }

    public function test_a_database_at_000029_records_what_was_posted_for_posted_commissions_only_and_can_lose_it_again(): void
    {
        // Commissions in every status, as the package kept them before
        // posted amounts existed: every posting then moved the whole amount.
        [, $commissions] = $this->legacyCommissions();
        $this->artisan('migrate', ['--path' => $this->migrations('000020', '000029'), '--realpath' => true])->assertSuccessful();
        $statuses = array_combine(array_values($commissions), ['posted', 'reversed', 'approved', 'cancelled', 'calculated']);

        foreach ($statuses as $id => $status) {
            DB::table('mlm_commissions')->where('id', $id)->update(['status' => $status, 'amount_millionths' => 10_000_000 + strlen($status)]);
        }

        $this->assertFalse(Schema::hasColumn('mlm_commissions', 'posted_amount_millionths'));
        $schema = $this->schemaOf();
        $tables = $this->existingTables();
        $rows = $this->rowsOf($tables);

        $this->artisan('migrate', ['--path' => $this->migration('000030'), '--realpath' => true])->assertSuccessful();

        $posted = [];

        foreach (DB::table('mlm_commissions')->get(['status', 'posted_amount_millionths']) as $row) {
            $posted[$row->status] = $row->posted_amount_millionths === null ? null : (string) $row->posted_amount_millionths;
        }

        ksort($posted);
        $this->assertSame(['approved' => null, 'calculated' => null, 'cancelled' => null, 'posted' => '10000006', 'reversed' => '10000008'], $posted);
        $this->assertSame($rows['mlm_commissions'], array_map(static fn (array $row): array => array_diff_key($row, ['posted_amount_millionths' => true]), $this->rowsOf(['mlm_commissions'])['mlm_commissions']));
        $this->assertSame(array_diff_key($rows, ['mlm_commissions' => true]), array_diff_key($this->rowsOf($tables), ['mlm_commissions' => true]));

        $this->artisan('migrate:rollback', ['--path' => $this->migration('000030'), '--realpath' => true])->assertSuccessful();

        $this->assertSame($schema, $this->schemaOf());
        $this->assertSame($rows, $this->rowsOf($tables));
        $this->assertSame(30, DB::table('migrations')->count());
    }

    public function test_the_matrix_tables_have_exactly_their_columns_and_keys(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['id', 'program_id', 'width', 'created_at', 'updated_at'], Schema::getColumnListing('mlm_matrix_networks'));
        $this->assertEqualsCanonicalizing(
            ['id', 'matrix_network_id', 'placement_edge_id', 'parent_id', 'slot', 'assigned_at', 'created_at', 'updated_at'],
            Schema::getColumnListing('mlm_matrix_placement_positions'),
        );
        $this->assertSame([['program_id']], $this->uniqueIndexColumns('mlm_matrix_networks'));
        $this->assertEqualsCanonicalizing([['placement_edge_id'], ['matrix_network_id', 'parent_id', 'slot']], $this->uniqueIndexColumns('mlm_matrix_placement_positions'));

        foreach (['width' => 'mlm_matrix_networks', 'slot' => 'mlm_matrix_placement_positions'] as $column => $table) {
            $this->assertStringContainsStringIgnoringCase('int', (string) collect(Schema::getColumns($table))->firstWhere('name', $column)['type_name']);
        }

        // The generic placement stays slotless.
        $this->assertEqualsCanonicalizing(['id', 'member_id', 'parent_id', 'placed_at', 'created_at', 'updated_at'], Schema::getColumnListing('mlm_placement_edges'));
    }

    public function test_a_database_at_000030_gains_an_empty_matrix_and_can_lose_it_again(): void
    {
        // Everything the package wrote before the matrix: sponsorship, generic
        // and binary placement, volume, a plan, a binary commission posted to
        // the ledger, and a binary correction of it.
        // The batch tables too, which the matrix migrations do not depend
        // on: posting reads them, and the code is always this release's.
        $this->travelTo(now()->setTime(12, 0));
        $this->artisan('migrate', ['--path' => [...$this->migrations('000001', '000030'), ...$this->migrations('000033', '000037')], '--realpath' => true])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('mlm_matrix_networks'));
        $this->pairingRun();
        $component = PlanComponent::query()->where('key', 'binary')->sole();
        $commission = Commission::query()->sole();
        $this->app->make(CommissionPoster::class)->post($this->app->make(CommissionLifecycle::class)->approve($this->app->make(CommissionLifecycle::class)->markPending($commission)));
        $this->app->make(VolumeRecorder::class)->reverse(new ReverseVolume(VolumeEntry::query()->where('idempotency_key', 'order:ORD-1')->sole(), 'refund', 'RF-1', 'refund:RF-1', now()->addDays(2)));
        $this->app->make(CalculationEngine::class)->calculate($component, new CalculationContext(now()->addDay(), now()->addDays(3), 'binary:2'));
        [$p, $l] = [Member::query()->whereKey(DB::table('mlm_placement_edges')->value('parent_id'))->sole(), Member::query()->whereKey(DB::table('mlm_placement_edges')->value('member_id'))->sole()];
        $this->app->make(SponsorGenealogy::class)->assignSponsor($l, $p);
        $this->assertSame(1, DB::table('mlm_binary_pairing_corrections')->count());
        $tables = $this->existingTables();
        $schema = $this->schemaOf();
        $rows = $this->rowsOf($tables);

        $this->artisan('migrate', ['--path' => $this->migrations('000031', '000032'), '--realpath' => true])->assertSuccessful();

        // Nothing is guessed into the matrix: no network, no position, no path.
        foreach (self::MATRIX_TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }

        $this->assertSame($rows, $this->rowsOf($tables));

        // Adopting an existing edge fills the overlay — and the rollback takes
        // its paths with its positions.
        $this->app->make(MatrixNetworkManager::class)->configure($p->program, 2);
        $this->app->make(MatrixPlacementManager::class)->adopt(PlacementEdge::query()->where('member_id', $l->id)->sole(), 2);
        $this->assertSame(3, DB::table('mlm_genealogy_paths')->where('tree_type', 'matrix')->count());

        $this->artisan('migrate:rollback', ['--path' => $this->migration('000032'), '--realpath' => true])->assertSuccessful();

        $this->assertSame(0, DB::table('mlm_genealogy_paths')->where('tree_type', 'matrix')->count());
        $this->assertFalse(Schema::hasTable('mlm_matrix_placement_positions'));

        $this->artisan('migrate:rollback', ['--path' => $this->migration('000031'), '--realpath' => true])->assertSuccessful();

        $this->assertSame($schema, $this->schemaOf());
        $this->assertSame($rows, $this->rowsOf($tables));
        $this->assertSame(35, DB::table('migrations')->count());
    }

    public function test_the_batch_tables_have_exactly_their_columns_and_keys(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            ['id', 'program_id', 'plan_version_id', 'source_ledger_account_id', 'idempotency_key', 'from_at', 'until_at', 'status', 'completed_at', 'created_at', 'updated_at'],
            Schema::getColumnListing('mlm_calculation_batches'),
        );
        $this->assertEqualsCanonicalizing(
            ['id', 'calculation_batch_id', 'plan_component_id', 'position', 'child_idempotency_key', 'calculation_run_id', 'created_at', 'updated_at'],
            Schema::getColumnListing('mlm_calculation_batch_items'),
        );
        $this->assertSame([['program_id', 'idempotency_key']], $this->uniqueIndexColumns('mlm_calculation_batches'));
        $this->assertEqualsCanonicalizing(
            [['calculation_run_id'], ['calculation_batch_id', 'plan_component_id'], ['calculation_batch_id', 'position'], ['calculation_batch_id', 'child_idempotency_key']],
            $this->uniqueIndexColumns('mlm_calculation_batch_items'),
        );

        foreach (['mlm_calculation_batches' => ['completed_at'], 'mlm_calculation_batch_items' => ['calculation_run_id']] as $table => $nullable) {
            foreach (Schema::getColumns($table) as $column) {
                $this->assertSame(in_array($column['name'], $nullable, true) || in_array($column['name'], ['created_at', 'updated_at'], true), $column['nullable'], "{$table}.{$column['name']}");
            }
        }

        // Runs stay standalone: no batch column of their own.
        $this->assertNotContains('calculation_batch_id', Schema::getColumnListing('mlm_calculation_runs'));
    }

    public function test_a_database_at_000032_gains_empty_calculation_batches_and_can_lose_them_again(): void
    {
        // Runs and commissions calculated before batches existed stay standalone.
        $this->artisan('migrate', ['--path' => [...$this->migrations('000001', '000032'), $this->migration('000035')], '--realpath' => true])->assertSuccessful();
        $this->pairingRun();
        $this->assertSame([1, 1], [DB::table('mlm_calculation_runs')->count(), DB::table('mlm_commissions')->count()]);
        $tables = $this->existingTables();
        $schema = $this->schemaOf();
        $rows = $this->rowsOf($tables);

        $this->artisan('migrate', ['--path' => $this->migrations('000033', '000034'), '--realpath' => true])->assertSuccessful();

        foreach (self::BATCH_TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }

        $this->assertSame($rows, $this->rowsOf($tables));

        $this->artisan('migrate:rollback', ['--path' => $this->migrations('000033', '000034'), '--realpath' => true])->assertSuccessful();

        $this->assertSame($schema, $this->schemaOf());
        $this->assertSame($rows, $this->rowsOf($tables));
        $this->assertSame(33, DB::table('migrations')->count());
    }

    public function test_the_period_tables_have_exactly_their_columns_and_keys(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            ['id', 'program_id', 'plan_version_id', 'source_ledger_account_id', 'idempotency_key', 'from_at', 'until_at', 'release_at', 'status', 'input_closed_at', 'calculated_at', 'finalized_at', 'released_at', 'created_at', 'updated_at'],
            Schema::getColumnListing('mlm_commission_periods'),
        );
        $this->assertEqualsCanonicalizing(
            ['id', 'commission_period_id', 'plan_component_id', 'calculation_run_id', 'position', 'created_at', 'updated_at'],
            Schema::getColumnListing('mlm_commission_period_runs'),
        );
        $this->assertSame([['program_id', 'idempotency_key']], $this->uniqueIndexColumns('mlm_commission_periods'));
        $this->assertContains(['program_id', 'from_at', 'until_at'], collect(Schema::getIndexes('mlm_commission_periods'))->pluck('columns')->all());
        $this->assertEqualsCanonicalizing(
            [['calculation_run_id'], ['commission_period_id', 'plan_component_id'], ['commission_period_id', 'position']],
            $this->uniqueIndexColumns('mlm_commission_period_runs'),
        );

        foreach (['held_at', 'available_at'] as $column) {
            $this->assertTrue(collect(Schema::getColumns('mlm_commissions'))->firstWhere('name', $column)['nullable'] ?? false, $column);
        }
    }

    public function test_a_database_at_000034_gains_empty_periods_and_can_lose_them_again(): void
    {
        // Runs, commissions — one posted — binary state and volume written
        // as 000034 left them; then the period migrations are undone, and
        // done again.
        $this->artisan('migrate')->assertSuccessful();
        $this->pairingRun();
        $commission = Commission::query()->sole();
        $this->app->make(CommissionPoster::class)->post($this->app->make(CommissionLifecycle::class)->approve($this->app->make(CommissionLifecycle::class)->markPending($commission)));
        $periods = $this->migrations('000035', '000037');
        $this->artisan('migrate:rollback', ['--path' => $periods, '--realpath' => true])->assertSuccessful();

        $this->assertFalse(Schema::hasTable('mlm_commission_periods'));
        $this->assertFalse(Schema::hasColumn('mlm_commissions', 'held_at'));
        $tables = $this->existingTables();
        $schema = $this->schemaOf();
        $rows = $this->rowsOf($tables);

        $this->artisan('migrate', ['--path' => $periods, '--realpath' => true])->assertSuccessful();

        // No period is inferred, and every commission keeps its status.
        foreach (self::PERIOD_TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }

        $this->assertSame(['posted'], DB::table('mlm_commissions')->pluck('status')->all());
        $this->assertSame($rows['mlm_commissions'], array_map(static fn (array $row): array => array_diff_key($row, ['held_at' => 1, 'available_at' => 1]), $this->rowsOf(['mlm_commissions'])['mlm_commissions']));
        $this->assertSame(array_diff_key($rows, ['mlm_commissions' => 1]), array_diff_key($this->rowsOf($tables), ['mlm_commissions' => 1]));

        $this->artisan('migrate:rollback', ['--path' => $periods, '--realpath' => true])->assertSuccessful();

        $this->assertSame($schema, $this->schemaOf());
        $this->assertSame($rows, $this->rowsOf($tables));
    }

    public function test_the_payout_tables_have_exactly_their_columns_and_keys(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $this->assertEqualsCanonicalizing([
            'id', 'program_id', 'member_id', 'wallet_id', 'settlement_ledger_account_id', 'currency', 'amount_millionths', 'destination_type', 'destination_reference',
            'idempotency_key', 'status', 'requested_at', 'approved_at', 'processing_at', 'settled_at', 'failed_at', 'cancelled_at', 'settlement_reference', 'failure_reason',
            'reservation_ledger_transaction_id', 'refund_ledger_transaction_id', 'created_at', 'updated_at',
        ], Schema::getColumnListing('mlm_payout_requests'));
        $this->assertEqualsCanonicalizing(
            ['id', 'program_id', 'currency', 'idempotency_key', 'status', 'sealed_at', 'processing_at', 'completed_at', 'cancelled_at', 'created_at', 'updated_at'],
            Schema::getColumnListing('mlm_payout_batches'),
        );
        $this->assertEqualsCanonicalizing(['id', 'payout_batch_id', 'payout_request_id', 'position', 'created_at', 'updated_at'], Schema::getColumnListing('mlm_payout_batch_items'));
        $this->assertEqualsCanonicalizing(
            [['program_id', 'idempotency_key'], ['program_id', 'settlement_reference'], ['reservation_ledger_transaction_id'], ['refund_ledger_transaction_id']],
            $this->uniqueIndexColumns('mlm_payout_requests'),
        );
        $this->assertSame([['program_id', 'idempotency_key']], $this->uniqueIndexColumns('mlm_payout_batches'));
        $this->assertEqualsCanonicalizing([['payout_request_id'], ['payout_batch_id', 'position']], $this->uniqueIndexColumns('mlm_payout_batch_items'));
        // No balance is stored anywhere: the ledger is the balance.
        $this->assertSame([], array_values(array_filter(Schema::getColumnListing('mlm_wallets'), static fn (string $column): bool => str_contains($column, 'balance'))));
    }

    public function test_a_database_at_000037_gains_empty_payout_tables_and_can_lose_them_again(): void
    {
        // Runs, a posted commission, binary state, wallets and the ledger as
        // 000037 left them; then the payout migrations are undone, and done
        // again.
        $this->artisan('migrate')->assertSuccessful();
        $this->pairingRun();
        $this->app->make(CommissionPoster::class)->post($this->app->make(CommissionLifecycle::class)->approve($this->app->make(CommissionLifecycle::class)->markPending(Commission::query()->sole())));
        $payouts = $this->migrations('000038', '000040');
        $this->artisan('migrate:rollback', ['--path' => $payouts, '--realpath' => true])->assertSuccessful();

        $this->assertFalse(Schema::hasTable('mlm_payout_requests'));
        $tables = $this->existingTables();
        $schema = $this->schemaOf();
        $rows = $this->rowsOf($tables);

        $this->artisan('migrate', ['--path' => $payouts, '--realpath' => true])->assertSuccessful();

        // No payout history is inferred.
        foreach (self::PAYOUT_TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }

        $this->assertSame($rows, $this->rowsOf($tables));

        $this->artisan('migrate:rollback', ['--path' => $payouts, '--realpath' => true])->assertSuccessful();

        $this->assertSame($schema, $this->schemaOf());
        $this->assertSame($rows, $this->rowsOf($tables));
    }

    public function test_a_database_at_000022_gains_empty_binary_pairing_state_and_can_lose_it_again(): void
    {
        // Everything the package wrote before binary pairing: genealogies, a
        // binary tree, volume, a plan and commissions.
        [$entry] = $this->legacyCommissions();
        $this->artisan('migrate', ['--path' => [$this->migration('000020'), $this->migration('000021'), $this->migration('000022')], '--realpath' => true])->assertSuccessful();
        $bob = Member::query()->findOrFail($entry->member_id);
        $alice = Member::query()->whereKeyNot($bob->id)->sole();
        $this->app->make(SponsorGenealogy::class)->assignSponsor($bob, $alice);
        $this->app->make(BinaryPlacementManager::class)->place($bob, $alice, BinarySide::Left);
        $tables = $this->existingTables();
        $schema = $this->schemaOf();
        $rows = $this->rowsOf($tables);

        $this->artisan('migrate', ['--path' => $this->migrations('000023', '000029'), '--realpath' => true])->assertSuccessful();

        // No carry is made up for history: the tables start empty.
        foreach (self::PAIRING_TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }

        $this->assertSame($rows, $this->rowsOf($tables));

        $this->artisan('migrate:rollback')->assertSuccessful();

        $this->assertSame($rows, $this->rowsOf($tables));
        $this->assertSame($schema, $this->schemaOf());
        $this->assertSame(23, DB::table('migrations')->count());
    }

    public function test_a_database_at_000021_gains_an_empty_binary_overlay_and_can_lose_it_again(): void
    {
        // Everything the package wrote before binary placement: genealogies,
        // volume, a plan, commissions and a clawback of them.
        [$entry] = $this->legacyCommissions();
        $this->artisan('migrate', ['--path' => [$this->migration('000020'), $this->migration('000021')], '--realpath' => true])->assertSuccessful();
        $bob = Member::query()->findOrFail($entry->member_id);
        $alice = Member::query()->whereKeyNot($bob->id)->sole();
        $carol = Member::factory()->for($bob->program)->create();
        $this->app->make(SponsorGenealogy::class)->assignSponsor($bob, $alice);
        $this->app->make(PlacementGenealogy::class)->place($bob, $alice);
        $this->app->make(PlacementGenealogy::class)->place($carol, $alice);
        $reversal = $this->app->make(VolumeRecorder::class)->reverse(new ReverseVolume($entry, 'refund', 'RF-1', 'refund:RF-1', now()->addDay()));
        $this->assertSame(4, $this->app->make(CommissionAdjustmentEngine::class)->processVolumeReversal($reversal)->count());
        $this->assertFalse(Schema::hasTable('mlm_binary_placement_positions'));
        $tables = $this->existingTables();
        $schema = $this->schemaOf();
        $rows = $this->rowsOf($tables);

        $this->artisan('migrate', ['--path' => $this->migration('000022'), '--realpath' => true])->assertSuccessful();

        // Nothing is guessed into the overlay: every edge stays generic-only.
        $this->assertSame(0, DB::table('mlm_binary_placement_positions')->count());
        $this->assertSame($rows, $this->rowsOf($tables));

        $this->app->make(BinaryPlacementManager::class)->adopt(PlacementEdge::query()->where('member_id', $carol->id)->sole(), BinarySide::Right);
        $this->assertSame(2, DB::table('mlm_genealogy_paths')->where('tree_type', 'binary')->where('depth', 0)->count());

        $this->artisan('migrate:rollback', ['--path' => $this->migration('000022'), '--realpath' => true])->assertSuccessful();

        // The overlay goes with its paths; everything else is as it was.
        $this->assertFalse(Schema::hasTable('mlm_binary_placement_positions'));
        $this->assertSame($rows, $this->rowsOf($tables));
        $this->assertSame($schema, $this->schemaOf());
    }

    public function test_a_database_at_000018_gains_the_source_entry_index_without_touching_its_rows_and_can_lose_it_again(): void
    {
        $migrations = array_map(
            static fn (string $file): string => dirname(__DIR__).'/database/migrations/'.$file,
            array_values(array_filter(scandir(dirname(__DIR__).'/database/migrations') ?: [], static fn (string $file): bool => preg_match('/_0000(0[1-9]|1[0-8])_/', $file) === 1)),
        );
        $this->assertCount(18, $migrations);
        // With the period table volume recording reads, as this release does.
        $migrations[] = $this->migration('000035');
        $this->artisan('migrate', ['--path' => $migrations, '--realpath' => true])->assertSuccessful();

        $program = Program::factory()->create();
        [$alice, $bob] = Member::factory()->for($program)->count(2)->create()->all();
        $this->app->make(SponsorGenealogy::class)->assignSponsor($bob, $alice);
        $entry = $this->app->make(VolumeRecorder::class)->record(new RecordVolume($bob, 'sales', Quantity::of('150'), 'order', 'ORD-1', 'order:ORD-1', now()));
        $this->app->make(VolumeRecorder::class)->reverse(new ReverseVolume($entry, 'refund', 'RF-1', 'refund:RF-1', now()->addDay()));
        $before = $this->rowsOf(self::TABLES_BEFORE_THE_LEDGER);
        $columns = ['program_id', 'type', 'source_type', 'effective_at'];

        $this->assertNotContains($columns, collect(Schema::getIndexes('mlm_volume_entries'))->pluck('columns')->all());

        $this->artisan('migrate')->assertSuccessful();

        $this->assertContains($columns, collect(Schema::getIndexes('mlm_volume_entries'))->pluck('columns')->all());
        $this->assertSame($before, $this->rowsOf(self::TABLES_BEFORE_THE_LEDGER));

        $this->artisan('migrate:rollback', [
            '--path' => dirname(__DIR__).'/database/migrations/2026_09_28_000019_add_program_source_index_to_mlm_volume_entries.php',
            '--realpath' => true,
        ])->assertSuccessful();

        $this->assertNotContains($columns, collect(Schema::getIndexes('mlm_volume_entries'))->pluck('columns')->all());
        $this->assertSame($before, $this->rowsOf(self::TABLES_BEFORE_THE_LEDGER));
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
        // Written as the release of the day wrote it: this release's recorder
        // also reads commission periods, which need the ledger.
        DB::table('mlm_volume_entries')->insert([
            'id' => strtolower((string) Str::ulid()), 'program_id' => $program->id, 'member_id' => $bob->id, 'type' => 'sales', 'quantity_millionths' => 150_000_000,
            'source_type' => 'order', 'source_id' => 'ORD-1', 'idempotency_key' => 'order:ORD-1', 'effective_at' => now(), 'reversal_of_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
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
     * A database at 000019 holding what the package wrote before commission
     * provenance existed: one original entry, and one commission of each
     * built-in source-entry strategy — plus one of an application's own —
     * whose traces name it.
     *
     * @return array{VolumeEntry, array<string, string>} the entry, and commission ids by strategy
     */
    private function legacyCommissions(): array
    {
        $migrations = array_map(
            static fn (string $file): string => dirname(__DIR__).'/database/migrations/'.$file,
            array_values(array_filter(scandir(dirname(__DIR__).'/database/migrations') ?: [], static fn (string $file): bool => preg_match('/_0000(0[1-9]|1[0-9])_/', $file) === 1)),
        );
        $this->assertCount(19, $migrations);
        // With the period table volume recording reads, as this release does.
        $migrations[] = $this->migration('000035');
        $this->artisan('migrate', ['--path' => $migrations, '--realpath' => true])->assertSuccessful();
        $this->assertFalse(Schema::hasColumn('mlm_commissions', 'source_type'));

        $program = Program::factory()->create();
        [$alice, $bob] = Member::factory()->for($program)->count(2)->create()->all();
        $entry = $this->app->make(VolumeRecorder::class)->record(new RecordVolume($bob, 'sales', Quantity::of('150'), 'order', 'ORD-1', 'order:ORD-1', now()));
        $source = $this->app->make(LedgerAccountManager::class)->openSystemAccount($program, 'IDR', 'commission.payable');
        $version = $this->app->make(PlanVersionLifecycle::class)->draft($program->plans()->create(['code' => 'MAIN', 'name' => 'Main']));
        $component = $this->app->make(PlanDefinitionEditor::class)->addComponent($version, 'direct', 'commission.strategy', 'Direct', [
            'strategy' => 'direct-sponsor.fixed', 'currency' => 'IDR', 'source_account' => 'commission.payable',
            'parameters' => ['volume_type' => 'sales', 'source_type' => 'order', 'minimum_quantity' => '0', 'amount' => '10'],
        ]);
        $this->app->make(PlanVersionLifecycle::class)->markValidated($version);
        $now = '2026-06-01 00:00:00';
        $commissions = [];

        foreach ([...self::BUILT_IN_STRATEGIES, 'acme.custom'] as $index => $strategy) {
            $run = strtolower((string) Str::ulid());
            DB::table('mlm_calculation_runs')->insert([
                'id' => $run, 'program_id' => $program->id, 'plan_version_id' => $version->id, 'plan_component_id' => $component->id,
                'strategy' => $strategy, 'currency' => 'IDR', 'source_ledger_account_id' => $source->id,
                'from_at' => $now, 'until_at' => '2026-07-01 00:00:00', 'idempotency_key' => "legacy:{$index}", 'created_at' => $now, 'updated_at' => $now,
            ]);
            $commissions[$strategy] = strtolower((string) Str::ulid());
            DB::table('mlm_commissions')->insert([
                'id' => $commissions[$strategy], 'calculation_run_id' => $run, 'program_id' => $program->id, 'member_id' => $alice->id,
                'candidate_key' => "volume-entry:{$entry->id}:depth:1", 'currency' => 'IDR', 'amount_millionths' => 10_000_000, 'earned_at' => $now,
                'trace' => json_encode(['amount' => '10', 'source' => ['volume_entry_id' => $entry->id], 'strategy' => $strategy]),
                'status' => 'calculated', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        return [$entry, $commissions];
    }

    /**
     * One binary pairing run that paired: P with L left and R right, 100
     * each, a pair of 100.
     */
    private function pairingRun(): void
    {
        $program = Program::factory()->create();
        [$p, $l, $r] = Member::factory()->for($program)->count(3)->create()->all();
        $this->app->make(BinaryPlacementManager::class)->place($l, $p, BinarySide::Left);
        $this->app->make(BinaryPlacementManager::class)->place($r, $p, BinarySide::Right);
        // After the sides were assigned, so the sales are in P's legs.
        $at = now()->addHour();
        $this->app->make(VolumeRecorder::class)->record(new RecordVolume($l, 'sales', Quantity::of('100'), 'order', 'ORD-1', 'order:ORD-1', $at));
        $this->app->make(VolumeRecorder::class)->record(new RecordVolume($r, 'sales', Quantity::of('100'), 'order', 'ORD-2', 'order:ORD-2', $at));
        $this->app->make(LedgerAccountManager::class)->openSystemAccount($program, 'IDR', 'commission.payable');
        $version = $this->app->make(PlanVersionLifecycle::class)->draft($program->plans()->create(['code' => 'BIN', 'name' => 'Binary']));
        $component = $this->app->make(PlanDefinitionEditor::class)->addComponent($version, 'binary', 'commission.strategy', 'Binary', [
            'strategy' => 'binary.pairing.fixed', 'currency' => 'IDR', 'source_account' => 'commission.payable',
            'parameters' => ['volume_type' => 'sales', 'pair_quantity' => '100', 'amount_per_pair' => '10'],
        ]);
        $this->app->make(PlanVersionLifecycle::class)->markValidated($version);

        $this->app->make(CalculationEngine::class)->calculate(PlanComponent::query()->findOrFail($component->id), new CalculationContext(now()->subDay(), now()->addDay(), 'binary:1'));
    }

    /**
     * The migration files numbered `$from` through `$to`, as real paths.
     *
     * @return list<string>
     */
    private function migrations(string $from, string $to): array
    {
        $files = array_values(array_filter(
            glob(dirname(__DIR__).'/database/migrations/2026_09_28_*.php') ?: [],
            static function (string $file) use ($from, $to): bool {
                $number = substr(basename($file), 11, 6);

                return $number >= $from && $number <= $to;
            },
        ));
        $this->assertCount((int) $to - (int) $from + 1, $files);

        return $files;
    }

    /**
     * The package's tables the database holds now.
     *
     * @return list<string>
     */
    private function existingTables(): array
    {
        return array_values(array_filter(self::TABLES, static fn (string $table): bool => Schema::hasTable($table)));
    }

    /**
     * The one migration file numbered `$number`, as a real path.
     */
    private function migration(string $number): string
    {
        $files = glob(dirname(__DIR__)."/database/migrations/2026_09_28_{$number}_*.php") ?: [];
        $this->assertCount(1, $files, $number);

        return $files[0];
    }

    /**
     * Every package table as the database describes it: columns, indexes
     * and foreign keys, in a stable order.
     *
     * @return array<string, array<string, mixed>>
     */
    private function schemaOf(): array
    {
        $tables = array_values(array_filter(
            array_column(Schema::getTables(), 'name'),
            static fn (string $table): bool => str_starts_with($table, 'mlm_'),
        ));
        sort($tables);
        $schema = [];

        foreach ($tables as $table) {
            $sorted = static function (array $items): array {
                usort($items, static fn (array $a, array $b): int => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));

                return $items;
            };

            $schema[$table] = [
                'columns' => Schema::getColumns($table),
                'indexes' => $sorted(Schema::getIndexes($table)),
                'foreign_keys' => $sorted(Schema::getForeignKeys($table)),
            ];
        }

        return $schema;
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
