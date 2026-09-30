<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Binary\BinarySide;
use PandaBear\Mlm\Calculation\CalculationBatchStatus;
use PandaBear\Mlm\Calculation\CalculationContext;
use PandaBear\Mlm\Calculation\CalculationEngine;
use PandaBear\Mlm\Commission\CommissionAdjustmentEngine;
use PandaBear\Mlm\Commission\CommissionCalculationContext;
use PandaBear\Mlm\Commission\CommissionCandidate;
use PandaBear\Mlm\Commission\CommissionStatus;
use PandaBear\Mlm\Commission\CommissionStrategyRegistry;
use PandaBear\Mlm\Commission\HybridCalculationEngine;
use PandaBear\Mlm\Commission\HybridCalculationResult;
use PandaBear\Mlm\Exceptions\ConflictingCalculationBatch;
use PandaBear\Mlm\Exceptions\CorruptCalculationBatch;
use PandaBear\Mlm\Exceptions\ImmutableCalculationRecord;
use PandaBear\Mlm\Exceptions\IncompleteCalculationBatch;
use PandaBear\Mlm\Exceptions\InvalidBinaryPairingRange;
use PandaBear\Mlm\Exceptions\InvalidHybridCalculation;
use PandaBear\Mlm\Exceptions\UnresolvedBinaryCorrection;
use PandaBear\Mlm\Models\CalculationBatch;
use PandaBear\Mlm\Models\CalculationBatchItem;
use PandaBear\Mlm\Models\CalculationRun;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Models\VolumeEntry;
use PandaBear\Mlm\Planning\Rules\MetricCondition;
use PandaBear\Mlm\Planning\Rules\RuleDefinition;
use PandaBear\Mlm\Tests\Concerns\BuildsBinaryPairing;
use PandaBear\Mlm\Tests\Concerns\BuildsCommissions;
use PandaBear\Mlm\Tests\Concerns\BuildsFixedCommissions;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;
use PandaBear\Mlm\Tests\Concerns\BuildsLedgers;
use PandaBear\Mlm\Tests\Concerns\BuildsPlanDefinitions;
use PandaBear\Mlm\Tests\Concerns\RecordsVolume;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Hybrid compensation is composition (ADR-028): every commission component
 * of one plan version, calculated as one auditable batch — a run per
 * component, through the unchanged calculation engine, in position order —
 * resumable after a failure, idempotent, and not posted until complete.
 */
final class HybridCalculationEngineTest extends DatabaseTestCase
{
    use BuildsBinaryPairing;
    use BuildsCommissions;
    use BuildsFixedCommissions;
    use BuildsGenealogies;
    use BuildsLedgers;
    use BuildsPlanDefinitions;
    use RecordsVolume;

    private Plan $plan;

    /**
     * @var array<string, Member>
     */
    private array $members;

    private LedgerAccount $source;

    protected function setUp(): void
    {
        parent::setUp();

        // Binary: ROOT > A left, ROOT > B right. Matrix: A > C, slot 1 — and
        // ROOT > A is binary only, A > C matrix only. S sponsors C.
        $this->plan = Plan::factory()->create();
        $this->members = $this->members($this->plan->program, 'ROOT', 'A', 'B', 'C', 'S');
        $this->matrixNetworks()->configure($this->plan->program, 3);
        $this->travelTo(CarbonImmutable::parse('2026-01-01 00:00:00'));
        $this->binary()->place($this->members['A'], $this->members['ROOT'], BinarySide::Left);
        $this->binary()->place($this->members['B'], $this->members['ROOT'], BinarySide::Right);
        $this->matrix()->place($this->members['C'], $this->members['A'], 1);
        $this->genealogy()->assignSponsor($this->members['C'], $this->members['S']);
        $this->travelBack();
        $this->source = $this->systemAccounts()->openSystemAccount($this->plan->program, 'IDR', 'commission.payable');

        foreach (['A', 'B', 'C'] as $code) {
            $this->sale($this->members[$code], '100', '2026-01-10', 'order:'.strtolower($code));
        }
    }

    public function test_the_commission_components_of_a_version_are_calculated_as_one_batch_in_order(): void
    {
        $version = $this->version(['unilevel' => 'unilevel.fixed', 'matrix' => 'matrix.proportional', 'binary' => 'binary.pairing.fixed'], ranks: true);

        $result = $this->hybrid($version);

        $batch = $result->batch;
        $this->assertSame([CalculationBatchStatus::Completed, $this->plan->program_id, $version->id, $this->source->id, 'hybrid:2026-01', '2026-01-01 00:00:00', '2026-02-01 00:00:00'], [
            $batch->status, $batch->program_id, $batch->plan_version_id, $batch->source_ledger_account_id, $batch->idempotency_key, $batch->from_at->format('Y-m-d H:i:s'), $batch->until_at->format('Y-m-d H:i:s'),
        ]);
        $this->assertNotNull($batch->completed_at);
        // The rank ladder is not a commission component: it is not calculated.
        $this->assertSame(['unilevel', 'matrix', 'binary'], array_map(static fn ($component): string => $component->key, $result->components()));
        $this->assertSame([1, 2, 3], array_map(static fn (CalculationBatchItem $item): int => $item->position, $result->items));
        $this->assertSame(['unilevel.fixed', 'matrix.proportional', 'binary.pairing.fixed'], array_map(static fn (CalculationRun $run): string => $run->strategy, $result->runs()));
        $this->assertSame(3, CalculationRun::query()->count());

        foreach ($result->runs() as $run) {
            // One program, range and source account, and a key of the batch's own.
            $this->assertSame([$this->plan->program_id, $version->id, $this->source->id, '2026-01-01 00:00:00', '2026-02-01 00:00:00', "hybrid:{$batch->id}:{$run->plan_component_id}"], [
                $run->program_id, $run->plan_version_id, $run->source_ledger_account_id, $run->from_at->format('Y-m-d H:i:s'), $run->until_at->format('Y-m-d H:i:s'), $run->idempotency_key,
            ]);
        }

        // Each network pays its own members: sponsorship S, the matrix A, the binary ROOT.
        $this->assertSame([['S', '3'], ['A', '5'], ['ROOT', '10']], array_map(fn (Commission $commission): array => [$this->code($commission), $commission->amount->value()], $result->commissions()));
        $this->assertSame([1, 1, 1], array_map(fn (CalculationRun $run): int => count($result->commissionsOf($run)), $result->runs()));
    }

    public function test_components_are_ordered_by_position_then_id(): void
    {
        $version = $this->version(['third' => ['unilevel.fixed', 3], 'first' => ['matrix.fixed', 1], 'tied' => ['direct-sponsor.fixed', 3]]);
        $tied = collect($version->components()->whereIn('key', ['third', 'tied'])->get())->sortBy('id')->pluck('key')->all();

        $this->assertSame(['first', ...$tied], array_map(static fn ($component): string => $component->key, $this->hybrid($version)->components()));
    }

    public function test_the_same_sale_pays_every_component_on_its_own_with_nothing_merged(): void
    {
        $version = $this->version(['direct' => 'direct-sponsor.fixed', 'unilevel' => 'unilevel.fixed']);

        $result = $this->hybrid($version);
        [$direct, $unilevel] = [$result->commissionsOf($result->runs()[0]), $result->commissionsOf($result->runs()[1])];

        // Two commissions for S from one entry — the same candidate key, in two runs.
        $sale = DB::table('mlm_volume_entries')->where('idempotency_key', 'order:c')->value('id');
        $this->assertSame([['S', '2', "volume-entry:{$sale}:depth:1", $sale]], array_map(fn (Commission $c): array => [$this->code($c), $c->amount->value(), $c->candidate_key, $c->source_id], $direct));
        $this->assertSame([['S', '3', "volume-entry:{$sale}:depth:1", $sale]], array_map(fn (Commission $c): array => [$this->code($c), $c->amount->value(), $c->candidate_key, $c->source_id], $unilevel));
        $this->assertSame(2, Commission::query()->where('member_id', $this->members['S']->id)->count());
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function nonHybridVersions(): array
    {
        return [
            'no commission component' => [[], 'has no commission component'],
            'one commission component' => [['matrix' => 'matrix.fixed'], 'has one commission component; calculate it with PandaBear\Mlm\Calculation\CalculationEngine'],
        ];
    }

    /**
     * @param  array<string, string>  $components
     */
    #[DataProvider('nonHybridVersions')]
    public function test_a_version_with_fewer_than_two_commission_components_is_refused(array $components, string $reason): void
    {
        $version = $this->version($components, ranks: true);

        try {
            $this->hybrid($version);
            $this->fail('A version that is not a composition was calculated as one.');
        } catch (InvalidHybridCalculation $exception) {
            $this->assertStringContainsString($reason, $exception->getMessage());
        }

        $this->assertSame([0, 0], [CalculationBatch::query()->count(), CalculationRun::query()->count()]);
    }

    public function test_a_draft_or_a_request_inside_a_transaction_is_refused(): void
    {
        $draft = $this->draft($this->plan);

        try {
            $this->hybrid($draft);
            $this->fail('A draft was calculated.');
        } catch (InvalidHybridCalculation $exception) {
            $this->assertStringContainsString('is a draft', $exception->getMessage());
        }

        $version = $this->version(['direct' => 'direct-sponsor.fixed', 'unilevel' => 'unilevel.fixed']);

        try {
            DB::transaction(fn () => $this->hybrid($version));
            $this->fail('A hybrid calculation ran inside a transaction.');
        } catch (InvalidHybridCalculation $exception) {
            $this->assertStringContainsString('does not start inside one already open', $exception->getMessage());
        }

        $this->assertSame(0, CalculationBatch::query()->count());
    }

    public function test_every_component_must_be_funded_from_the_batchs_account(): void
    {
        $version = $this->version(['direct' => 'direct-sponsor.fixed', 'unilevel' => 'unilevel.fixed']);
        $other = $this->systemAccounts()->openSystemAccount($this->plan->program, 'IDR', 'other.payable');
        $foreign = $this->systemAccounts()->openSystemAccount(Plan::factory()->create()->program, 'IDR', 'commission.payable');

        foreach ([
            [$other, 'is funded from "commission.payable" in IDR, not "other.payable" in IDR'],
            [$foreign, 'not the plan\'s program'],
            [$this->walletAccount($this->members['S']), 'not a system account'],
        ] as [$account, $reason]) {
            try {
                $this->hybrid($version, source: $account, key: 'funding:'.$account->id);
                $this->fail('A batch was funded from an account its components do not use.');
            } catch (InvalidHybridCalculation $exception) {
                $this->assertStringContainsString($reason, $exception->getMessage());
            }
        }

        $this->assertSame([0, 0], [CalculationBatch::query()->count(), CalculationRun::query()->count()]);
    }

    public function test_a_completed_batch_is_returned_as_stored_and_nothing_is_calculated_again(): void
    {
        $version = $this->version(['binary' => 'binary.pairing.fixed', 'matrix' => 'matrix.fixed']);
        $first = $this->hybrid($version);
        $state = [$this->pairingState(), $this->rows('mlm_calculation_batches'), $this->rows('mlm_calculation_batch_items')];
        $writes = 0;
        DB::listen(static function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)/i', $query->sql) === 1) {
                $writes++;
            }
        });

        $again = $this->hybrid($version);

        $this->assertSame(0, $writes);
        $this->assertTrue($again->batch->is($first->batch));
        $this->assertSame($this->ids($first->runs()), $this->ids($again->runs()));
        $this->assertSame($this->ids($first->commissions()), $this->ids($again->commissions()));
        // The binary run, results, allocations, carry and cursor are exactly as they were.
        $this->assertEquals($state, [$this->pairingState(), $this->rows('mlm_calculation_batches'), $this->rows('mlm_calculation_batch_items')]);
    }

    public function test_binary_state_moves_once_and_the_stateless_matrix_keeps_none(): void
    {
        $version = $this->version(['binary' => 'binary.pairing.fixed', 'matrix' => 'matrix.fixed', 'unilevel' => 'unilevel.fixed']);

        $result = $this->hybrid($version);
        $binary = $version->components()->where('key', 'binary')->sole();

        $this->assertSame(['ROOT' => ['left' => '0 + 100 - 0 = 100 -> 0', 'right' => '0 + 100 - 0 = 100 -> 0', 'pairs' => '1 x 100 = 100', 'commission' => true]], $this->pairingResults($result->runs()[0]));
        $this->assertSame('2026-02-01 00:00:00', (string) DB::table('mlm_binary_pairing_cursors')->where('plan_component_id', $binary->id)->value('through_at'));
        $this->assertSame([1, 2], [DB::table('mlm_binary_pairing_cursors')->count(), DB::table('mlm_binary_pairing_allocations')->count()]);
        $this->assertSame(['binary', 'binary'], DB::table('mlm_binary_carry_lots')->pluck('plan_component_id')->map(fn (string $id): string => $version->components()->whereKey($id)->value('key'))->all());
        $this->assertSame(0, DB::table('mlm_genealogy_paths')->whereNotIn('tree_type', ['sponsor', 'placement', 'binary', 'matrix'])->count());
    }

    public function test_a_failed_component_leaves_the_batch_open_and_a_retry_resumes_it(): void
    {
        $strategy = $this->scriptedStrategy();
        $version = $this->version(['one' => 'test.scripted', 'two' => 'test.scripted', 'three' => 'test.scripted']);
        $failing = $version->components()->where('key', 'two')->value('id');
        $strategy->script = function (CommissionCalculationContext $context) use (&$failing): iterable {
            if ($context->planComponentId === $failing) {
                throw new RuntimeException('Component two failed.');
            }

            return [new CommissionCandidate('scripted', $this->members['A'], '1', CarbonImmutable::parse('2026-01-15'))];
        };

        try {
            $this->hybrid($version);
            $this->fail('A failing component completed the batch.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Component two failed.', $exception->getMessage());
        }

        $batch = CalculationBatch::query()->sole();
        $items = $batch->items()->get();
        $first = CalculationRun::query()->sole();
        // The first run stands, linked; the others were not linked, the third not even tried.
        $this->assertSame([CalculationBatchStatus::Open, null], [$batch->status, $batch->completed_at]);
        $this->assertSame([$first->id, null, null], $items->pluck('calculation_run_id')->all());
        $this->assertSame(2, $strategy->calculations);

        $failing = null;
        $result = $this->hybrid($version);

        $this->assertSame(CalculationBatchStatus::Completed, $result->batch->status);
        $this->assertTrue($result->batch->is($batch));
        $this->assertSame($first->id, $result->runs()[0]->id);
        $this->assertSame([3, 3, 4], [CalculationRun::query()->count(), Commission::query()->count(), $strategy->calculations]);
        // The same candidate key in three runs: three commissions.
        $this->assertSame(['scripted', 'scripted', 'scripted'], array_map(static fn (Commission $commission): string => $commission->candidate_key, $result->commissions()));
    }

    public function test_a_run_committed_before_its_link_is_found_again_and_is_not_posted_meanwhile(): void
    {
        $version = $this->version(['direct' => 'direct-sponsor.fixed', 'unilevel' => 'unilevel.fixed']);
        $crash = true;
        DB::listen(static function (QueryExecuted $query) use (&$crash): void {
            if ($crash && str_starts_with($query->sql, 'update') && str_contains($query->sql, 'mlm_calculation_batch_items')) {
                throw new RuntimeException('The process died.');
            }
        });

        try {
            $this->hybrid($version);
            $this->fail('The link was written.');
        } catch (RuntimeException) {
        }

        // The first component's run committed; its item was never linked.
        $run = CalculationRun::query()->sole();
        $this->assertNull(CalculationBatchItem::query()->where('position', 1)->value('calculation_run_id'));
        $commission = $this->approved($run->commissions()->sole());

        try {
            $this->poster()->post($commission);
            $this->fail('A commission of an open batch was posted.');
        } catch (IncompleteCalculationBatch $exception) {
            $this->assertStringContainsString('is still open', $exception->getMessage());
        }

        $crash = false;
        $result = $this->hybrid($version);

        $this->assertSame($run->id, $result->runs()[0]->id);
        $this->assertSame(2, CalculationRun::query()->count());
        $this->assertSame(CommissionStatus::Posted, $this->poster()->post($commission)->status);
    }

    public function test_a_commission_of_an_open_batch_is_not_posted_until_the_batch_completes(): void
    {
        $strategy = $this->scriptedStrategy();
        $version = $this->version(['one' => 'test.scripted', 'two' => 'test.scripted']);
        $failing = $version->components()->where('key', 'two')->value('id');
        $strategy->script = function (CommissionCalculationContext $context) use (&$failing): iterable {
            if ($context->planComponentId === $failing) {
                throw new RuntimeException('Not yet.');
            }

            return [new CommissionCandidate('scripted', $this->members['A'], '1', CarbonImmutable::parse('2026-01-15'))];
        };

        try {
            $this->hybrid($version);
        } catch (RuntimeException) {
        }

        $commission = $this->approved(Commission::query()->sole());

        try {
            $this->poster()->post($commission);
            $this->fail('A commission of an open batch was posted.');
        } catch (IncompleteCalculationBatch) {
        }

        $this->assertSame([CommissionStatus::Approved, 0], [$commission->refresh()->status, DB::table('mlm_ledger_transactions')->count()]);

        $failing = null;
        $this->hybrid($version);

        $this->assertSame([CommissionStatus::Posted, '1'], [$this->poster()->post($commission)->status, $commission->refresh()->postedAmount?->value()]);

        // A run calculated on its own is posted as ever.
        $standalone = $this->approved($this->app->make(CalculationEngine::class)->calculate($version->components()->where('key', 'one')->sole(), new CalculationContext(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-02-01'), 'standalone'))->commissions()->sole());
        $this->assertNull($standalone->run->batchItem);
        $this->assertSame(CommissionStatus::Posted, $this->poster()->post($standalone)->status);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function conflictingRequests(): array
    {
        return ['another plan version' => ['version'], 'another from' => ['from'], 'another until' => ['until'], 'another source account' => ['account']];
    }

    #[DataProvider('conflictingRequests')]
    public function test_the_same_key_with_another_request_is_refused(string $conflict): void
    {
        $version = $this->version(['direct' => 'direct-sponsor.fixed', 'unilevel' => 'unilevel.fixed']);
        $this->hybrid($version);
        $runs = CalculationRun::query()->count();
        $other = $this->version(['direct' => 'direct-sponsor.fixed', 'matrix' => 'matrix.fixed']);

        try {
            match ($conflict) {
                'version' => $this->hybrid($other),
                'from' => $this->hybrid($version, from: '2026-01-02'),
                'until' => $this->hybrid($version, until: '2026-02-02'),
                'account' => $this->hybrid($version, source: $this->systemAccounts()->openSystemAccount($this->plan->program, 'IDR', 'other.payable')),
            };
            $this->fail('A key was reused for another request.');
        } catch (ConflictingCalculationBatch $exception) {
            $this->assertStringContainsString('already holds idempotency key "hybrid:2026-01"', $exception->getMessage());
            $this->assertStringContainsString(['version' => 'plan version', 'from' => 'from', 'until' => 'until', 'account' => 'source account'][$conflict], $exception->getMessage());
        }

        $this->assertSame([1, $runs], [CalculationBatch::query()->count(), CalculationRun::query()->count()]);
    }

    public function test_a_stored_batch_whose_items_or_links_were_changed_is_refused_not_resumed(): void
    {
        $version = $this->version(['direct' => 'direct-sponsor.fixed', 'unilevel' => 'unilevel.fixed']);
        $result = $this->hybrid($version);
        $item = $result->items[1];

        // A raw write: the second item points at the first run.
        DB::table('mlm_calculation_batch_items')->where('id', $item->id)->update(['calculation_run_id' => null]);
        DB::table('mlm_calculation_batch_items')->where('id', $result->items[0]->id)->update(['calculation_run_id' => null]);
        DB::table('mlm_calculation_batch_items')->where('id', $item->id)->update(['calculation_run_id' => $result->runs()[0]->id]);

        try {
            $this->hybrid($version);
            $this->fail('A wrong link was read.');
        } catch (CorruptCalculationBatch $exception) {
            $this->assertStringContainsString('belongs to another component', $exception->getMessage());
        }

        // A raw write: an item is gone.
        DB::table('mlm_calculation_batch_items')->where('id', $item->id)->delete();

        try {
            $this->hybrid($version);
            $this->fail('A batch missing a component was resumed.');
        } catch (CorruptCalculationBatch $exception) {
            $this->assertStringContainsString('holds 1 item(s) where the version has 2 commission component(s)', $exception->getMessage());
        }
    }

    public function test_another_key_is_another_request_and_a_stateful_component_refuses_a_range_it_has_calculated(): void
    {
        $version = $this->version(['matrix' => 'matrix.fixed', 'binary' => 'binary.pairing.fixed']);
        $this->hybrid($version);

        try {
            $this->hybrid($version, key: 'hybrid:2026-01:again');
            $this->fail('The binary range was calculated twice.');
        } catch (InvalidBinaryPairingRange $exception) {
            $this->assertStringContainsString('already calculated through', $exception->getMessage());
        }

        // The second batch stays open, its stateless run standing.
        $second = CalculationBatch::query()->where('idempotency_key', 'hybrid:2026-01:again')->sole();
        $this->assertSame([CalculationBatchStatus::Open, 1], [$second->status, $second->items()->whereNotNull('calculation_run_id')->count()]);
        $this->assertSame(1, DB::table('mlm_binary_pairing_results')->count());
    }

    public function test_later_reversals_are_corrected_by_their_own_engines(): void
    {
        $version = $this->version(['binary' => 'binary.pairing.fixed', 'matrix' => 'matrix.fixed', 'unilevel' => 'unilevel.fixed']);

        foreach ($this->hybrid($version)->commissions() as $commission) {
            $this->poster()->post($this->approved($commission));
        }

        // C's sale fed the matrix and the sponsor line: the source-reversal
        // engine corrects both.
        $reversal = $this->reverse($this->entry('order:c'), 'refund:c', at: CarbonImmutable::parse('2026-02-05'));
        $corrected = collect($this->app->make(CommissionAdjustmentEngine::class)->processVolumeReversal($reversal)->adjustments);

        $this->assertSame([['A', 'reversed'], ['S', 'reversed']], $corrected->map(fn ($adjustment): array => [$this->code($adjustment->commission), $adjustment->outcome->value])->sortBy(0)->values()->all());

        // A's sale fed the binary pair: the pairing run undoes it, and the
        // binary engine corrects its commission.
        $binaryReversal = $this->reverse($this->entry('order:a'), 'refund:a', at: CarbonImmutable::parse('2026-02-05'));
        $this->pair($version->components()->where('key', 'binary')->sole(), '2026-02-01', '2026-03-01');
        $adjustment = $this->app->make(CommissionAdjustmentEngine::class)->processBinaryReversal($binaryReversal)->adjustments[0];

        $this->assertSame(['ROOT', '-10', 'reversed'], [$this->code($adjustment->commission), $adjustment->amount->value(), $adjustment->outcome->value]);
        $this->assertFalse(method_exists(CommissionAdjustmentEngine::class, 'processHybridReversal'));
    }

    public function test_a_completed_batchs_binary_commission_keeps_its_correction_guard_and_net_posting(): void
    {
        // The pair's left is A's 30, then 70 of A's 100: refunding the 30
        // undoes 30 of the pair — 3 of its 10.
        $small = $this->sale($this->members['A'], '30', '2026-01-05', 'order:a-small');
        $version = $this->version(['binary' => 'binary.pairing.fixed', 'matrix' => 'matrix.fixed']);
        $binary = $this->approved($this->hybrid($version)->commissionsOf($version->components()->where('key', 'binary')->sole())[0]);
        $reversal = $this->reverse($small, 'refund:a-small', at: CarbonImmutable::parse('2026-02-05'));
        $this->pair($version->components()->where('key', 'binary')->sole(), '2026-02-01', '2026-03-01');

        try {
            $this->poster()->post($binary);
            $this->fail('An unresolved binary correction was posted.');
        } catch (UnresolvedBinaryCorrection) {
        }

        $this->app->make(CommissionAdjustmentEngine::class)->processBinaryReversal($reversal);
        $posted = $this->poster()->post($binary);

        $this->assertSame(['10', '7'], [$posted->amount->value(), $posted->postedAmount?->value()]);
    }

    public function test_the_batch_is_read_relationally_and_written_by_the_engine_alone(): void
    {
        $version = $this->version(['direct' => 'direct-sponsor.fixed', 'unilevel' => 'unilevel.fixed']);
        $result = $this->hybrid($version);
        $batch = CalculationBatch::query()->sole();

        $this->assertTrue($batch->program->is($this->plan->program));
        $this->assertTrue($batch->planVersion->is($version));
        $this->assertTrue($batch->sourceAccount->is($this->source));
        $this->assertSame(['direct', 'unilevel'], $batch->items->map(static fn (CalculationBatchItem $item): string => $item->component->key)->all());
        $this->assertSame($this->ids($result->runs()), $batch->items->map(static fn (CalculationBatchItem $item): string => (string) $item->run?->id)->all());
        $this->assertTrue($result->runs()[1]->batchItem?->batch->is($batch));
        $this->assertSame(['S'], array_map(fn (Commission $commission): string => $this->code($commission), $batch->items[1]->run?->commissions->all() ?? []));

        foreach ([
            static fn () => CalculationBatch::query()->forceCreate([]),
            static fn () => $batch->forceFill(['status' => 'open'])->save(),
            static fn () => $batch->delete(),
            static fn () => CalculationBatchItem::query()->forceCreate([]),
            static fn () => $batch->items[0]->forceFill(['position' => 9])->save(),
            static fn () => $batch->items[0]->delete(),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A batch row was written through Eloquent.');
            } catch (ImmutableCalculationRecord $exception) {
                $this->assertStringContainsString(HybridCalculationEngine::class, $exception->getMessage());
            }
        }
    }

    public function test_hybrid_is_composition_not_a_strategy(): void
    {
        $this->assertSame([], array_values(array_filter($this->app->make(CommissionStrategyRegistry::class)->keys(), static fn (string $key): bool => str_contains($key, 'hybrid'))));
        $this->assertSame(
            [CalculationEngine::class],
            array_map(static fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(), (new \ReflectionClass(HybridCalculationEngine::class))->getConstructor()?->getParameters() ?? []),
        );
    }

    /**
     * A validated version of the plan with these commission components, by
     * key — a strategy, or a strategy and a position — and, if asked, a rank
     * ladder beside them.
     *
     * @param  array<string, string|array{string, int}>  $components
     */
    private function version(array $components, bool $ranks = false): PlanVersion
    {
        $draft = $this->draft($this->plan);

        foreach ($components as $key => $component) {
            [$strategy, $position] = is_array($component) ? $component : [$component, null];
            $this->editor()->addComponent($draft, $key, 'commission.strategy', ucfirst($key), $this->commissionParameters(['strategy' => $strategy, 'parameters' => $this->parametersOf($strategy)]), $position);
        }

        if ($ranks) {
            $ladder = $this->editor()->addComponent($draft, 'career-ranks', 'rank.ladder', 'Career Ranks', [], 0);
            $this->editor()->addRule($ladder, 'bronze', 'Bronze', RuleDefinition::all(MetricCondition::of('member.volume', ['type' => 'sales'], '>=', '100')), 10);
        }

        $this->lifecycle()->markValidated($draft);

        return PlanVersion::query()->findOrFail($draft->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function parametersOf(string $strategy): array
    {
        $source = ['volume_type' => 'sales', 'source_type' => 'order', 'minimum_quantity' => '1'];

        return match ($strategy) {
            'direct-sponsor.fixed' => [...$source, 'amount' => '2'],
            'unilevel.fixed' => [...$source, 'levels' => [['depth' => 1, 'amount' => '3']]],
            'matrix.fixed' => [...$source, 'levels' => [['depth' => 1, 'amount' => '5']]],
            'matrix.proportional' => [...$source, 'rounding' => 'half_even', 'levels' => [['depth' => 1, 'unit_amount' => '0.05']]],
            'binary.pairing.fixed' => ['volume_type' => 'sales', 'pair_quantity' => '100', 'amount_per_pair' => '10'],
            'test.scripted' => [],
        };
    }

    private function hybrid(PlanVersion $version, ?LedgerAccount $source = null, string $key = 'hybrid:2026-01', string $from = '2026-01-01', string $until = '2026-02-01'): HybridCalculationResult
    {
        return $this->app->make(HybridCalculationEngine::class)->calculate(
            $version,
            new CalculationContext(CarbonImmutable::parse($from), CarbonImmutable::parse($until), $key),
            $source ?? $this->source,
        );
    }

    private function entry(string $key): VolumeEntry
    {
        return VolumeEntry::query()->where('idempotency_key', $key)->sole();
    }

    private function code(Commission $commission): string
    {
        return Member::query()->findOrFail($commission->member_id)->member_code;
    }

    /**
     * @param  list<CalculationRun|Commission>  $models
     * @return list<string>
     */
    private function ids(array $models): array
    {
        return array_map(static fn (CalculationRun|Commission $model): string => (string) $model->getKey(), $models);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $table): array
    {
        return DB::table($table)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
    }
}
