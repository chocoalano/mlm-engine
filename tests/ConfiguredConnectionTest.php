<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PandaBear\Mlm\Binary\BinarySide;
use PandaBear\Mlm\Calculation\CalculationContext;
use PandaBear\Mlm\Commission\CommissionAdjustmentEngine;
use PandaBear\Mlm\Commission\CommissionAdjustmentOutcome;
use PandaBear\Mlm\Commission\CommissionStatus;
use PandaBear\Mlm\Commission\HybridCalculationEngine;
use PandaBear\Mlm\Exceptions\FinalizedCommissionPeriod;
use PandaBear\Mlm\Exceptions\InvalidCalculationRun;
use PandaBear\Mlm\Metrics\MetricContext;
use PandaBear\Mlm\Metrics\MetricEngine;
use PandaBear\Mlm\Models\BinaryCarryLot;
use PandaBear\Mlm\Models\BinaryPairingAllocation;
use PandaBear\Mlm\Models\BinaryPairingCorrection;
use PandaBear\Mlm\Models\BinaryPairingCursor;
use PandaBear\Mlm\Models\BinaryPairingRestoration;
use PandaBear\Mlm\Models\BinaryPairingResult;
use PandaBear\Mlm\Models\BinaryPlacementPosition;
use PandaBear\Mlm\Models\CalculationBatch;
use PandaBear\Mlm\Models\CalculationBatchItem;
use PandaBear\Mlm\Models\CalculationRun;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\CommissionAdjustment;
use PandaBear\Mlm\Models\CommissionPeriod;
use PandaBear\Mlm\Models\CommissionPeriodRun;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\LedgerPosting;
use PandaBear\Mlm\Models\LedgerTransaction;
use PandaBear\Mlm\Models\MatrixNetwork;
use PandaBear\Mlm\Models\MatrixPlacementPosition;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\PayoutBatch;
use PandaBear\Mlm\Models\PayoutBatchItem;
use PandaBear\Mlm\Models\PayoutRequest;
use PandaBear\Mlm\Models\PlacementEdge;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanComponent;
use PandaBear\Mlm\Models\PlanRule;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Models\SponsorEdge;
use PandaBear\Mlm\Models\VolumeEntry;
use PandaBear\Mlm\Models\Wallet;
use PandaBear\Mlm\Payout\PayoutBatchManager;
use PandaBear\Mlm\Payout\PayoutBatchTotals;
use PandaBear\Mlm\Payout\PayoutManager;
use PandaBear\Mlm\Period\CommissionPeriodCalculator;
use PandaBear\Mlm\Period\CommissionPeriodFinalizer;
use PandaBear\Mlm\Period\CommissionPeriodManager;
use PandaBear\Mlm\Period\CommissionPeriodReleaser;
use PandaBear\Mlm\Period\CommissionPeriodTotals;
use PandaBear\Mlm\Planning\PlanVersionLifecycle;
use PandaBear\Mlm\Planning\Rules\MetricCondition;
use PandaBear\Mlm\Planning\Rules\RuleDefinition;
use PandaBear\Mlm\Qualification\QualificationContext;
use PandaBear\Mlm\Qualification\QualificationEngine;
use PandaBear\Mlm\Rank\RankContext;
use PandaBear\Mlm\Rank\RankEngine;
use PandaBear\Mlm\Tests\Concerns\BuildsCommissions;
use PandaBear\Mlm\Tests\Concerns\BuildsFixedCommissions;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;
use PandaBear\Mlm\Tests\Concerns\BuildsLedgers;
use PandaBear\Mlm\Tests\Concerns\BuildsPlanDefinitions;
use PandaBear\Mlm\Tests\Concerns\RecordsVolume;

/**
 * `mlm.database.connection` names a connection other than the default, and
 * the migrations, the models, the plan lifecycle, both genealogies, the
 * volume history and the metrics read from it all follow it.
 */
final class ConfiguredConnectionTest extends DatabaseTestCase
{
    use BuildsCommissions;
    use BuildsFixedCommissions;
    use BuildsGenealogies;
    use BuildsLedgers;
    use BuildsPlanDefinitions;
    use RecordsVolume;

    private const TABLES = ['mlm_programs', 'mlm_members', 'mlm_plans', 'mlm_plan_versions', 'mlm_sponsor_edges', 'mlm_genealogy_paths', 'mlm_placement_edges', 'mlm_volume_entries', 'mlm_plan_components', 'mlm_plan_rules', 'mlm_wallets', 'mlm_ledger_accounts', 'mlm_ledger_transactions', 'mlm_ledger_postings', 'mlm_calculation_runs', 'mlm_commissions', 'mlm_commission_adjustments', 'mlm_binary_placement_positions', 'mlm_binary_pairing_cursors', 'mlm_binary_carry_lots', 'mlm_binary_pairing_results', 'mlm_binary_pairing_allocations', 'mlm_binary_pairing_corrections', 'mlm_binary_pairing_restorations', 'mlm_matrix_networks', 'mlm_matrix_placement_positions', 'mlm_calculation_batches', 'mlm_calculation_batch_items', 'mlm_commission_periods', 'mlm_commission_period_runs', 'mlm_payout_requests', 'mlm_payout_batches', 'mlm_payout_batch_items'];

    private const MODELS = [Program::class, Member::class, Plan::class, PlanVersion::class, SponsorEdge::class, PlacementEdge::class, VolumeEntry::class, PlanComponent::class, PlanRule::class, Wallet::class, LedgerAccount::class, LedgerTransaction::class, LedgerPosting::class, CalculationRun::class, Commission::class, CommissionAdjustment::class, BinaryPlacementPosition::class, BinaryPairingCursor::class, BinaryCarryLot::class, BinaryPairingResult::class, BinaryPairingAllocation::class, BinaryPairingCorrection::class, BinaryPairingRestoration::class, MatrixNetwork::class, MatrixPlacementPosition::class, CalculationBatch::class, CalculationBatchItem::class, CommissionPeriod::class, CommissionPeriodRun::class, PayoutRequest::class, PayoutBatch::class, PayoutBatchItem::class];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');

        $config->set('database.connections.mlm', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $config->set('mlm.database.connection', 'mlm');
    }

    public function test_the_migrations_build_on_the_configured_connection(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::connection('mlm')->hasTable($table), "{$table} is not on [mlm].");
            $this->assertFalse(Schema::connection('testing')->hasTable($table), "{$table} is on the default connection.");
        }

        // The upgrade ran there too, and left nothing behind anywhere.
        $this->assertTrue(Schema::connection('mlm')->hasColumn('mlm_genealogy_paths', 'effective_from'));
        $this->assertFalse(Schema::connection('mlm')->hasTable('mlm_genealogy_paths_replay_000009'));
        $this->assertFalse(Schema::connection('testing')->hasTable('mlm_genealogy_paths_replay_000009'));
    }

    public function test_the_models_use_the_configured_connection(): void
    {
        foreach (self::MODELS as $model) {
            $this->assertSame('mlm', (new $model)->getConnectionName(), $model);
        }

        $program = Program::factory()->create();
        $member = Member::factory()->for($program)->create();

        $this->assertDatabaseHas('mlm_programs', ['id' => $program->id], 'mlm');
        $this->assertDatabaseHas('mlm_members', ['id' => $member->id], 'mlm');
        $this->assertTrue($program->members()->sole()->is($member));
    }

    public function test_the_lifecycle_runs_on_the_configured_connection(): void
    {
        $lifecycle = $this->app->make(PlanVersionLifecycle::class);
        $plan = Plan::factory()->create();

        $v1 = $lifecycle->activate($lifecycle->publish($lifecycle->markValidated($lifecycle->draft($plan))));
        $v2 = $lifecycle->activate($lifecycle->publish($lifecycle->markValidated($lifecycle->draft($plan))));

        $this->assertDatabaseHas('mlm_plan_versions', ['id' => $v1->id, 'status' => 'superseded'], 'mlm');
        $this->assertDatabaseHas('mlm_plan_versions', ['id' => $v2->id, 'status' => 'active'], 'mlm');
        $this->assertTrue($plan->currentActiveVersion()?->is($v2));
    }

    public function test_the_sponsor_genealogy_writes_and_reads_on_the_configured_connection(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob', 'Charlie');

        $this->sponsorTree($members, ['Alice' => ['Bob'], 'Bob' => ['Charlie']]);

        $this->assertSame(['Alice > Bob', 'Bob > Charlie'], $this->genealogyState('mlm')['edges']);
        $this->assertCount(6, $this->genealogyState('mlm')['paths']);
        $this->assertSame(['Bob@1', 'Charlie@2'], $this->relatives($this->genealogy()->descendants($members['Alice'])));
        $this->assertSame(['Bob@1', 'Alice@2'], $this->relatives($this->genealogy()->ancestors($members['Charlie'])));
        $this->assertTrue($this->genealogy()->directSponsor($members['Bob'])?->is($members['Alice']));
        $this->assertSame(['Charlie'], $this->genealogy()->directMembers($members['Bob'])->pluck('member_code')->all());
    }

    public function test_sponsor_history_is_written_and_read_on_the_configured_connection(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob', 'Charlie');

        $this->travelTo(CarbonImmutable::parse('2026-01-01 00:00:00'));
        $this->sponsorTree($members, ['Alice' => ['Bob']]);
        $this->travelTo(CarbonImmutable::parse('2026-03-01 00:00:00'));
        $this->sponsorTree($members, ['Bob' => ['Charlie']]);

        $this->assertSame('2026-03-01 00:00:00', $this->pathMoments('sponsor', 'mlm')['Alice > Charlie @2']);

        $february = CarbonImmutable::parse('2026-02-01 00:00:00');
        $this->assertSame(['Bob@1'], $this->relatives($this->genealogy()->descendantsAt($members['Alice'], $february)));
        $this->assertSame([], $this->relatives($this->genealogy()->ancestorsAt($members['Charlie'], $february)));
        $this->assertNull($this->genealogy()->directSponsorAt($members['Charlie'], $february));
        $this->assertSame(['Bob'], $this->genealogy()->directMembersAt($members['Alice'], $february)->pluck('member_code')->all());
    }

    public function test_placement_history_is_written_and_read_on_the_configured_connection(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob', 'Charlie');

        $this->travelTo(CarbonImmutable::parse('2026-01-01 00:00:00'));
        $this->placementTree($members, ['Alice' => ['Bob']]);
        $this->travelTo(CarbonImmutable::parse('2026-03-01 00:00:00'));
        $this->placementTree($members, ['Bob' => ['Charlie']]);

        $this->assertSame('2026-03-01 00:00:00', $this->pathMoments('placement', 'mlm')['Alice > Charlie @2']);

        $february = CarbonImmutable::parse('2026-02-01 00:00:00');
        $this->assertSame(['Bob@1'], $this->relatives($this->placement()->descendantsAt($members['Alice'], $february)));
        $this->assertSame([], $this->relatives($this->placement()->ancestorsAt($members['Charlie'], $february)));
        $this->assertNull($this->placement()->directParentAt($members['Charlie'], $february));
        $this->assertSame(['Bob'], $this->placement()->directChildrenAt($members['Alice'], $february)->pluck('member_code')->all());
    }

    public function test_the_placement_genealogy_writes_and_reads_on_the_configured_connection(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob', 'Charlie');

        $this->placementTree($members, ['Alice' => ['Bob'], 'Bob' => ['Charlie']]);

        $this->assertSame(['Alice > Bob', 'Bob > Charlie'], $this->placementState('mlm')['edges']);
        $this->assertCount(6, $this->placementState('mlm')['paths']);
        $this->assertSame(['Bob@1', 'Charlie@2'], $this->relatives($this->placement()->descendants($members['Alice'])));
        $this->assertSame(['Bob@1', 'Alice@2'], $this->relatives($this->placement()->ancestors($members['Charlie'])));
        $this->assertTrue($this->placement()->directParent($members['Bob'])?->is($members['Alice']));
        $this->assertSame(['Charlie'], $this->placement()->directChildren($members['Bob'])->pluck('member_code')->all());
    }

    public function test_the_binary_overlay_writes_reads_and_totals_on_the_configured_connection(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob', 'Charlie', 'Dave');
        $this->travelTo(CarbonImmutable::parse('2026-01-01 00:00:00'));
        $this->binary()->place($members['Bob'], $members['Alice'], BinarySide::Left);
        $this->binary()->adopt($this->placement()->place($members['Charlie'], $members['Alice']), BinarySide::Right);
        $this->binary()->place($members['Dave'], $members['Bob'], BinarySide::Right);

        $this->record($members['Bob'], '20', 'order:ORD-1', at: CarbonImmutable::parse('2026-02-01 00:00:00'));
        $this->reverse($this->record($members['Dave'], '5', 'order:ORD-2', at: CarbonImmutable::parse('2026-02-01 00:00:00')), 'refund:RF-1');
        $this->record($members['Dave'], '1', 'order:ORD-3', at: CarbonImmutable::parse('2026-02-01 00:00:00'));
        $this->record($members['Charlie'], '7', 'order:ORD-4', at: CarbonImmutable::parse('2026-02-01 00:00:00'));

        $this->assertCount(3, $this->binaryState('mlm')['positions']);
        $this->assertSame(['Bob@1', 'Charlie@1', 'Dave@2'], $this->relatives($this->binaryTree()->descendants($members['Alice'])));
        $this->assertSame('Charlie', $this->binaryTree()->child($members['Alice'], BinarySide::Right)?->member_code);
        $this->assertSame('Bob', $this->binaryTree()->directParent($members['Dave'])?->member_code);

        // The overlay exists only on [mlm]: a query on the default connection
        // would fail, not return zero.
        $engine = $this->app->make(MetricEngine::class);

        $this->assertSame('21', $engine->resolve('binary.left.volume', new MetricContext($members['Alice'], ['type' => 'sales']))->value());
        $this->assertSame('7', $engine->resolve('binary.right.volume', new MetricContext($members['Alice'], ['type' => 'sales', 'max_depth' => 1]))->value());
        $this->assertFalse(Schema::connection('testing')->hasTable('mlm_binary_placement_positions'));
    }

    public function test_binary_pairing_reads_and_keeps_its_state_on_the_configured_connection(): void
    {
        $plan = Plan::factory()->create();
        $members = $this->members($plan->program, 'Alice', 'Bob', 'Charlie');
        $this->travelTo(CarbonImmutable::parse('2026-01-01 00:00:00'));
        $this->binary()->place($members['Bob'], $members['Alice'], BinarySide::Left);
        $this->binary()->place($members['Charlie'], $members['Alice'], BinarySide::Right);
        $this->travelBack();
        $component = $this->fixedComponent('binary.pairing.fixed', ['volume_type' => 'sales', 'pair_quantity' => '100', 'amount_per_pair' => '10'], $plan);
        $sale = $this->sale($members['Bob'], '250', '2026-01-10', 'order:ORD-1');
        $this->sale($members['Charlie'], '120', '2026-01-10', 'order:ORD-2');

        // Everything exists only on [mlm]: a read or write on the default
        // connection would fail, not find nothing.
        $run = $this->monthly($component, '2026-01');
        $this->sale($members['Charlie'], '180', '2026-02-10', 'order:ORD-3');
        $this->monthly($component, '2026-02');

        $this->assertSame('10', $run->commissions()->sole()->amount->value());
        $this->assertSame(['1', '1'], BinaryPairingResult::query()->orderBy('calculation_run_id')->pluck('pair_count')->all());
        $this->assertSame(3, DB::connection('mlm')->table('mlm_binary_carry_lots')->count());
        $this->assertSame(5, BinaryPairingAllocation::query()->count());
        $this->assertSame('2026-03-01 00:00:00', BinaryPairingCursor::query()->sole()->through_at->format('Y-m-d H:i:s'));

        // March: Bob's sale, paired twice, is reversed; its pairs are undone
        // and Charlie's side gets its carry back — all on [mlm].
        $this->reverse($sale, 'refund:RF-1', at: CarbonImmutable::parse('2026-03-05 00:00:00'));
        $this->monthly($component, '2026-03');

        $this->assertSame([2, 3], [BinaryPairingCorrection::query()->count(), BinaryPairingRestoration::query()->count()]);
        // Charlie's 120 and 180 are all carry again; Bob's lot holds nothing.
        $this->assertSame(['300000000', '0'], [(string) DB::connection('mlm')->table('mlm_binary_carry_lots')->where('member_id', $members['Alice']->id)->where('side', 'right')->sum('remaining_millionths'), (string) DB::connection('mlm')->table('mlm_binary_carry_lots')->where('source_volume_entry_id', $sale->id)->value('remaining_millionths')]);
        $this->assertFalse(Schema::connection('testing')->hasTable('mlm_binary_carry_lots'));
        $this->assertFalse(Schema::connection('testing')->hasTable('mlm_binary_pairing_corrections'));
    }

    public function test_volume_is_recorded_reversed_and_totalled_on_the_configured_connection(): void
    {
        ['Alice' => $alice] = $this->members(Program::factory()->create(), 'Alice');

        $entry = $this->record($alice, '20', 'order:ORD-1');
        $this->record($alice, '5', 'order:ORD-2');
        $this->reverse($entry, 'refund:RF-1');

        $this->assertCount(3, $this->volumeRows('mlm'));
        $this->assertSame('5', $this->totals()->forMember($alice, 'sales')->value());
        $this->assertTrue($this->record($alice, '20', 'order:ORD-1')->is($entry));
    }

    public function test_metrics_resolve_from_the_configured_connection(): void
    {
        ['Alice' => $alice] = $this->members(Program::factory()->create(), 'Alice');
        $this->record($alice, '20', 'order:ORD-1');
        $this->reverse($this->record($alice, '5', 'order:ORD-2'), 'refund:RF-1');

        // The volume table exists only on [mlm]: a query on the default
        // connection would fail, not return zero.
        $value = $this->app->make(MetricEngine::class)->resolve('member.volume', new MetricContext($alice, ['type' => 'sales']));

        $this->assertSame('20', $value->value());
        $this->assertFalse(Schema::connection('testing')->hasTable('mlm_volume_entries'));
    }

    public function test_network_metrics_resolve_from_the_configured_connection(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob', 'Charlie');
        $this->travelTo(CarbonImmutable::parse('2026-01-01 00:00:00'));
        $this->sponsorTree($members, ['Alice' => ['Bob']]);
        $this->placementTree($members, ['Alice' => ['Charlie']]);

        $this->record($members['Bob'], '20', 'order:ORD-1', at: CarbonImmutable::parse('2026-02-01 00:00:00'));
        $this->reverse($this->record($members['Bob'], '5', 'order:ORD-2', at: CarbonImmutable::parse('2026-02-01 00:00:00')), 'refund:RF-1');
        $this->record($members['Charlie'], '7', 'order:ORD-3', at: CarbonImmutable::parse('2026-02-01 00:00:00'));

        // Genealogy and volume exist only on [mlm]: a query on the default
        // connection would fail, not return zero.
        $engine = $this->app->make(MetricEngine::class);

        $this->assertSame('20', $engine->resolve('sponsor.network.volume', new MetricContext($members['Alice'], ['type' => 'sales']))->value());
        $this->assertSame('7', $engine->resolve('placement.network.volume', new MetricContext($members['Alice'], ['type' => 'sales', 'max_depth' => 1]))->value());
        $this->assertFalse(Schema::connection('testing')->hasTable('mlm_genealogy_paths'));
    }

    public function test_plan_definitions_are_edited_validated_and_cloned_on_the_configured_connection(): void
    {
        $version = $this->validDraft();
        $component = $version->components()->sole();

        $this->assertSame('mlm', $component->getConnectionName());
        $this->assertSame('mlm', $component->rules()->sole()->getConnectionName());
        $this->assertSame($this->qualifyingRule()->toArray(), $component->rules()->sole()->definition->toArray());

        $validated = $this->lifecycle()->markValidated($version);
        $clone = $this->cloner()->cloneToNewDraft($validated);

        $this->assertSame($this->storedDefinition($validated), $this->storedDefinition($clone));
        $this->assertDatabaseHas('mlm_plan_versions', ['id' => $clone->id, 'status' => 'draft', 'version' => 2], 'mlm');
        $this->assertSame(2, DB::connection('mlm')->table('mlm_plan_components')->count());
        $this->assertSame(2, DB::connection('mlm')->table('mlm_plan_rules')->count());
        $this->assertFalse(Schema::connection('testing')->hasTable('mlm_plan_components'));
    }

    public function test_a_rule_is_evaluated_on_the_configured_connection(): void
    {
        $plan = Plan::factory()->create();
        $member = Member::factory()->for($plan->program)->create();
        $this->record($member, '150', 'order:ORD-1');
        $rule = $this->validatedRule(RuleDefinition::all(MetricCondition::of('member.volume', ['type' => 'sales'], '>=', '100')), $plan);

        $decision = $this->app->make(QualificationEngine::class)->evaluate($rule, new QualificationContext($member));

        $this->assertTrue($decision->qualified);
        $this->assertSame('150', $decision->toArray()['trace']['children'][0]['value']);
        $this->assertFalse(Schema::connection('testing')->hasTable('mlm_plan_rules'));
    }

    public function test_a_rank_ladder_is_defined_and_evaluated_on_the_configured_connection(): void
    {
        $plan = Plan::factory()->create();
        $member = Member::factory()->for($plan->program)->create();
        $this->record($member, '150', 'order:ORD-1');
        $ladder = $this->validatedLadder([
            'bronze' => [10, RuleDefinition::all(MetricCondition::of('member.volume', ['type' => 'sales'], '>=', '100'))],
            'silver' => [20, RuleDefinition::all(MetricCondition::of('member.volume', ['type' => 'sales'], '>=', '200'))],
        ], $plan);

        $decision = $this->app->make(RankEngine::class)->evaluate($ladder, new RankContext($member));

        $this->assertSame('mlm', $ladder->getConnectionName());
        $this->assertSame('bronze', $decision->selectedRank?->key);
        $this->assertSame('150', $decision->toArray()['ranks'][1]['trace']['children'][0]['value']);
        $this->assertSame(2, DB::connection('mlm')->table('mlm_plan_rules')->where('plan_component_id', $ladder->id)->count());
        $this->assertFalse(Schema::connection('testing')->hasTable('mlm_plan_components'));
    }

    public function test_the_ledger_opens_posts_reverses_and_reads_on_the_configured_connection(): void
    {
        $member = Member::factory()->create();
        $wallet = $this->wallets()->open($member, 'IDR');
        $clearing = $this->systemAccounts()->openSystemAccount($member->program, 'IDR', 'adjustment.clearing');
        $account = $wallet->account;
        $this->assertNotNull($account);

        $credit = $this->postLedger($member->program, [[$clearing, '-100'], [$account, '100']]);
        $this->postLedger($member->program, [[$clearing, '-25.5'], [$account, '25.5']], 'adjustment:ADJ-2', 'ADJ-2');
        $this->reverseLedger($credit);

        $this->assertSame('mlm', $credit->getConnectionName());
        $this->assertSame('25.5', $this->balances()->forWallet($wallet)->value());
        $this->assertSame('-25.5', $this->balances()->forAccount($clearing)->value());
        $this->assertSame([1, 2, 3, 6], [
            DB::connection('mlm')->table('mlm_wallets')->count(),
            DB::connection('mlm')->table('mlm_ledger_accounts')->count(),
            DB::connection('mlm')->table('mlm_ledger_transactions')->count(),
            DB::connection('mlm')->table('mlm_ledger_postings')->count(),
        ]);
        $this->assertFalse(Schema::connection('testing')->hasTable('mlm_ledger_postings'));
    }

    public function test_binary_commissions_are_corrected_financially_on_the_configured_connection(): void
    {
        $plan = Plan::factory()->create();
        $members = $this->members($plan->program, 'Alice', 'Bob', 'Charlie');
        $this->travelTo(CarbonImmutable::parse('2026-01-01 00:00:00'));
        $this->binary()->place($members['Bob'], $members['Alice'], BinarySide::Left);
        $this->binary()->place($members['Charlie'], $members['Alice'], BinarySide::Right);
        $this->travelBack();
        $component = $this->fixedComponent('binary.pairing.fixed', ['volume_type' => 'sales', 'pair_quantity' => '100', 'amount_per_pair' => '100'], $plan);
        $small = $this->sale($members['Bob'], '30', '2026-01-10', 'order:ORD-1');
        $this->sale($members['Bob'], '70', '2026-01-11', 'order:ORD-2');
        $this->sale($members['Charlie'], '100', '2026-01-10', 'order:ORD-3');
        $commission = $this->approved($this->monthly($component, '2026-01')->commissions()->sole());
        $reversal = $this->reverse($small, 'refund:RF-1', at: CarbonImmutable::parse('2026-02-05 00:00:00'));
        $this->monthly($component, '2026-02');

        // The journal, the adjustments and the posting are all read and
        // written on [mlm].
        $adjustment = $this->app->make(CommissionAdjustmentEngine::class)->processBinaryReversal($reversal)->adjustments[0];
        $posted = $this->poster()->post($commission);

        $this->assertSame(['mlm', '-30', CommissionAdjustmentOutcome::Recorded], [$adjustment->getConnectionName(), $adjustment->amount->value(), $adjustment->outcome]);
        $this->assertSame(['mlm', '70', '70'], [$posted->getConnectionName(), $posted->postedAmount?->value(), $this->balances()->forWallet(Wallet::query()->sole())->value()]);
        $this->assertSame(1, DB::connection('mlm')->table('mlm_commission_adjustments')->count());
        $this->assertFalse(Schema::connection('testing')->hasTable('mlm_commission_adjustments'));
    }

    public function test_the_matrix_writes_reads_totals_and_pays_on_the_configured_connection(): void
    {
        $plan = Plan::factory()->create();
        $members = $this->members($plan->program, 'Alice', 'Bob', 'Charlie', 'Dave');
        $this->travelTo(CarbonImmutable::parse('2026-01-01 00:00:00'));
        $network = $this->matrixNetworks()->configure($plan->program, 3);
        $this->matrix()->place($members['Bob'], $members['Alice'], 2);
        $this->matrix()->adopt($this->placement()->place($members['Charlie'], $members['Bob']), 1);
        $this->placement()->place($members['Dave'], $members['Charlie']);
        $this->travelBack();
        $sale = $this->sale($members['Charlie'], '100', '2026-01-10', 'order:ORD-1');
        $this->sale($members['Dave'], '100', '2026-01-10', 'order:ORD-2');
        $component = $this->fixedComponent('matrix.fixed', ['volume_type' => 'sales', 'source_type' => 'order', 'minimum_quantity' => '1', 'levels' => [['depth' => 1, 'amount' => '10'], ['depth' => 2, 'amount' => '5']]], $plan);

        // Everything exists only on [mlm]: a read or write on the default
        // connection would fail, not find nothing.
        $run = $this->monthly($component, '2026-01');

        $this->assertSame(['mlm', 'mlm'], [$network->getConnectionName(), $this->matrixTree()->positionOf($members['Charlie'])?->getConnectionName()]);
        $this->assertSame(['Bob@1', 'Alice@2'], $this->relatives($this->matrixTree()->ancestors($members['Charlie'])));
        $this->assertSame('100', $this->app->make(MetricEngine::class)->resolve('matrix.network.volume', new MetricContext($members['Alice'], ['type' => 'sales']))->value());
        $this->assertSame([['Bob', '10'], ['Alice', '5']], $run->commissions()->orderByDesc('amount_millionths')->get()->map(fn (Commission $commission): array => [Member::query()->findOrFail($commission->member_id)->member_code, $commission->amount->value()])->all());
        $this->assertSame(2, DB::connection('mlm')->table('mlm_commissions')->where('source_id', $sale->id)->count());
        $this->assertSame(2, DB::connection('mlm')->table('mlm_matrix_placement_positions')->count());
        $this->assertFalse(Schema::connection('testing')->hasTable('mlm_matrix_networks'));
    }

    public function test_a_hybrid_batch_is_calculated_and_posted_on_the_configured_connection(): void
    {
        $plan = Plan::factory()->create();
        $members = $this->members($plan->program, 'Alice', 'Bob', 'Charlie');
        $this->sponsorAt($members['Bob'], $members['Alice'], '2026-01-01 00:00:00');
        $this->sponsorAt($members['Charlie'], $members['Bob'], '2026-01-01 00:00:00');
        $this->sale($members['Charlie'], '150', '2026-01-10', 'order:ORD-1');
        $source = $this->systemAccounts()->openSystemAccount($plan->program, 'IDR', 'commission.payable');
        $draft = $this->draft($plan);
        $this->addCommissionComponent($draft, $this->commissionParameters(['strategy' => 'direct-sponsor.fixed', 'parameters' => $this->directParameters()]), 'direct');
        $this->addCommissionComponent($draft, $this->commissionParameters(['strategy' => 'unilevel.fixed', 'parameters' => $this->unilevelParameters()]), 'unilevel');
        $this->lifecycle()->markValidated($draft);

        // Batches, items, runs and commissions exist only on [mlm].
        $result = $this->app->make(HybridCalculationEngine::class)->calculate($draft->refresh(), new CalculationContext(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-02-01'), 'hybrid:2026-01'), $source);
        $posted = $this->poster()->post($this->approved($result->commissions()[0]));

        $this->assertSame(['mlm', 'completed'], [$result->batch->getConnectionName(), $result->batch->status->value]);
        $this->assertSame([1, 2, 2, 3], [DB::connection('mlm')->table('mlm_calculation_batches')->count(), DB::connection('mlm')->table('mlm_calculation_batch_items')->count(), DB::connection('mlm')->table('mlm_calculation_runs')->count(), DB::connection('mlm')->table('mlm_commissions')->count()]);
        $this->assertSame(CommissionStatus::Posted, $posted->status);
        $this->assertFalse(Schema::connection('testing')->hasTable('mlm_calculation_batches'));
    }

    public function test_a_commission_period_runs_its_whole_life_on_the_configured_connection(): void
    {
        $plan = Plan::factory()->create();
        $members = $this->members($plan->program, 'Alice', 'Bob');
        $this->sponsorAt($members['Bob'], $members['Alice'], '2026-01-01 00:00:00');
        $this->sale($members['Bob'], '150', '2026-01-10', 'order:ORD-1');
        $source = $this->systemAccounts()->openSystemAccount($plan->program, 'IDR', 'commission.payable');
        $draft = $this->draft($plan);
        $this->addCommissionComponent($draft, $this->commissionParameters(['strategy' => 'direct-sponsor.fixed', 'parameters' => $this->directParameters()]), 'direct');
        $this->lifecycle()->markValidated($draft);
        $this->lifecycle()->publish($draft->refresh());
        $this->lifecycle()->activate($draft->refresh());

        // Periods, their runs, the input guard, the lifecycle and posting all
        // read and write [mlm].
        $period = $this->app->make(CommissionPeriodManager::class)->create($plan->program, $draft->refresh(), $source, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-02-01'), CarbonImmutable::parse('2026-02-15'), 'period:2026-01');
        $commission = $this->approved($this->app->make(CommissionPeriodCalculator::class)->calculate($period)->commissions()[0]);
        $this->app->make(CommissionPeriodFinalizer::class)->finalize($period);
        $released = $this->app->make(CommissionPeriodReleaser::class)->release($period, CarbonImmutable::parse('2026-02-15'));
        $posted = $this->poster()->post($commission);

        $this->assertSame(['mlm', 'released', CommissionStatus::Posted], [$released->getConnectionName(), $released->status->value, $posted->status]);
        $this->assertSame('10', CommissionPeriodTotals::of($released)->posted->value());
        $this->assertSame([1, 1], [DB::connection('mlm')->table('mlm_commission_periods')->count(), DB::connection('mlm')->table('mlm_commission_period_runs')->count()]);
        $this->assertFalse(Schema::connection('testing')->hasTable('mlm_commission_periods'));

        $this->expectException(FinalizedCommissionPeriod::class);
        $this->sale($members['Bob'], '1', '2026-01-20', 'order:late');
    }

    public function test_payouts_and_their_batches_run_their_whole_life_on_the_configured_connection(): void
    {
        $member = Member::factory()->create(['member_code' => 'Alice']);
        $account = LedgerAccount::query()->where('wallet_id', $this->wallets()->open($member, 'IDR')->id)->sole();
        $clearing = $this->systemAccounts()->openSystemAccount($member->program, 'IDR', 'adjustment.clearing');
        $settlement = $this->systemAccounts()->openSystemAccount($member->program, 'IDR', 'payout.settlement');
        $this->ledger()->post($this->postCommand($member->program, [[$clearing, '-100'], [$account, '100']]));
        $payouts = $this->app->make(PayoutManager::class);
        $batches = $this->app->make(PayoutBatchManager::class);

        // Requests, reservations, batches and refunds read and write [mlm].
        $settled = $payouts->approve($payouts->request($member, Wallet::query()->sole(), $settlement, '60', 'bank-account', 'dest:1', now(), 'payout:1'), now());
        $failed = $payouts->approve($payouts->request($member, Wallet::query()->sole(), $settlement, '40', 'bank-account', 'dest:1', now(), 'payout:2'), now());
        $batch = $batches->create($member->program, 'IDR', 'batch:1');
        $batches->add($batch, $settled);
        $batches->add($batch, $failed);
        $batches->startProcessing($batches->seal($batch, now()), now());
        $payouts->settle($settled, 'BANK-1', now());
        $payouts->fail($failed, 'account-closed', now());
        $completed = $batches->complete($batch, now());

        $this->assertSame(['mlm', 'completed', '40'], [$completed->getConnectionName(), $completed->status->value, $this->balances()->forWallet(Wallet::query()->sole())->value()]);
        $this->assertSame([2, 1, 2], [DB::connection('mlm')->table('mlm_payout_requests')->count(), DB::connection('mlm')->table('mlm_payout_batches')->count(), DB::connection('mlm')->table('mlm_payout_batch_items')->count()]);
        $this->assertSame('60', PayoutBatchTotals::of($completed)->settled->value());
        $this->assertFalse(Schema::connection('testing')->hasTable('mlm_payout_requests'));
    }

    public function test_commissions_are_calculated_reviewed_posted_and_reversed_on_the_configured_connection(): void
    {
        $component = $this->commissionComponent();
        Member::factory()->for($component->planVersion->plan->program)->count(2)->create();

        $run = $this->calculate($component);
        $commission = $this->poster()->post($this->approved($run->commissions()->firstOrFail()));
        $reversed = $this->poster()->reverse($commission, CarbonImmutable::parse('2026-08-01'));

        $this->assertSame(['mlm', 'mlm'], [$run->getConnectionName(), $reversed->getConnectionName()]);
        $this->assertSame([1, 2], [DB::connection('mlm')->table('mlm_calculation_runs')->count(), DB::connection('mlm')->table('mlm_commissions')->count()]);
        $this->assertSame(2, DB::connection('mlm')->table('mlm_ledger_transactions')->count());
        $this->assertFalse(Schema::connection('testing')->hasTable('mlm_commissions'));

        // A transaction open on the package's connection is refused: the
        // calculation must own its snapshot there.
        $this->expectException(InvalidCalculationRun::class);

        DB::connection('mlm')->transaction(fn () => $this->calculate($component, key: 'run:inside'));
    }

    public function test_the_built_in_strategies_read_and_store_on_the_configured_connection(): void
    {
        $plan = Plan::factory()->create();
        $members = $this->members($plan->program, 'ALICE', 'BOB', 'CHARLIE');
        $this->sponsorTree($members, ['ALICE' => ['BOB'], 'BOB' => ['CHARLIE']]);
        $this->record($members['CHARLIE'], '150', 'order:A', at: CarbonImmutable::now()->addMinute());
        $component = $this->commissionComponent(['strategy' => 'unilevel.fixed', 'parameters' => [
            'volume_type' => 'sales', 'source_type' => 'order', 'minimum_quantity' => '100',
            'levels' => [['depth' => 1, 'amount' => '10'], ['depth' => 2, 'amount' => '5']],
        ]], $plan);

        // The default connection has no package tables: a read that left the
        // calculation's connection would fail here.
        $run = $this->calculate($component, CarbonImmutable::now()->subDay()->format('Y-m-d H:i:s'), CarbonImmutable::now()->addDay()->format('Y-m-d H:i:s'));

        $this->assertSame('mlm', $run->getConnectionName());
        $this->assertSame(
            [$members['BOB']->id => '10', $members['ALICE']->id => '5'],
            $run->commissions()->get()->mapWithKeys(static fn (Commission $commission): array => [$commission->member_id => $commission->amount->value()])->all(),
        );
        $this->assertSame(2, DB::connection('mlm')->table('mlm_commissions')->count());
        $this->assertFalse(Schema::connection('testing')->hasTable('mlm_volume_entries'));
    }

    public function test_the_proportional_strategies_read_and_store_on_the_configured_connection(): void
    {
        $plan = Plan::factory()->create();
        $members = $this->members($plan->program, 'ALICE', 'BOB');
        $this->sponsorTree($members, ['ALICE' => ['BOB']]);
        $this->record($members['BOB'], '1.234567', 'order:A', at: CarbonImmutable::now()->addMinute());
        $component = $this->commissionComponent(['strategy' => 'direct-sponsor.proportional', 'parameters' => [
            'volume_type' => 'sales', 'source_type' => 'order', 'minimum_quantity' => '0', 'unit_amount' => '2.345678', 'rounding' => 'half_up',
        ]], $plan);

        // The default connection has no package tables: a read that left the
        // calculation's connection would fail here.
        $run = $this->calculate($component, CarbonImmutable::now()->subDay()->format('Y-m-d H:i:s'), CarbonImmutable::now()->addDay()->format('Y-m-d H:i:s'));
        $commission = $run->commissions()->sole();

        $this->assertSame(['mlm', $members['ALICE']->id, '2.895897'], [$commission->getConnectionName(), $commission->member_id, $commission->amount->value()]);
        $this->assertSame('2.895896651426', $commission->trace['calculation']['exact_amount']);
        $this->assertFalse(Schema::connection('testing')->hasTable('mlm_commissions'));
    }

    public function test_clawbacks_find_correct_and_record_on_the_configured_connection(): void
    {
        $plan = Plan::factory()->create();
        $members = $this->members($plan->program, 'ALICE', 'BOB');
        $this->sponsorTree($members, ['ALICE' => ['BOB']]);
        $entry = $this->record($members['BOB'], '150', 'order:A', at: CarbonImmutable::now()->addMinute());
        $component = $this->commissionComponent(['strategy' => 'direct-sponsor.fixed', 'parameters' => [
            'volume_type' => 'sales', 'source_type' => 'order', 'minimum_quantity' => '100', 'amount' => '10',
        ]], $plan);
        $run = $this->calculate($component, CarbonImmutable::now()->subDay()->format('Y-m-d H:i:s'), CarbonImmutable::now()->addDay()->format('Y-m-d H:i:s'));
        $this->poster()->post($this->approved($run->commissions()->sole()));

        $reversal = $this->reverse($entry, 'refund:A', at: CarbonImmutable::now()->addDays(2));
        $result = $this->app->make(CommissionAdjustmentEngine::class)->processVolumeReversal($reversal);

        $this->assertSame(1, $result->count(CommissionAdjustmentOutcome::Reversed));
        $this->assertSame('mlm', $result->adjustments[0]->getConnectionName());
        $this->assertSame(1, DB::connection('mlm')->table('mlm_commission_adjustments')->count());
        $this->assertSame('reversed', DB::connection('mlm')->table('mlm_commissions')->value('status'));
        $this->assertFalse(Schema::connection('testing')->hasTable('mlm_commission_adjustments'));
    }

    public function test_a_connection_set_on_the_model_still_wins(): void
    {
        foreach (self::MODELS as $model) {
            $this->assertSame('testing', (new $model)->setConnection('testing')->getConnectionName(), $model);
        }
    }
}
