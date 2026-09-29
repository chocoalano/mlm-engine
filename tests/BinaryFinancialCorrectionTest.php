<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PandaBear\Mlm\Binary\BinarySide;
use PandaBear\Mlm\Commission\CommissionAdjustmentEngine;
use PandaBear\Mlm\Commission\CommissionAdjustmentOutcome;
use PandaBear\Mlm\Commission\CommissionAdjustmentResult;
use PandaBear\Mlm\Commission\CommissionNetAmount;
use PandaBear\Mlm\Commission\CommissionStatus;
use PandaBear\Mlm\Exceptions\InvalidCommissionAdjustment;
use PandaBear\Mlm\Exceptions\InvalidCommissionPosting;
use PandaBear\Mlm\Exceptions\UnresolvedBinaryCorrection;
use PandaBear\Mlm\Models\BinaryPairingCorrection;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\CommissionAdjustment;
use PandaBear\Mlm\Models\LedgerTransaction;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanComponent;
use PandaBear\Mlm\Models\VolumeEntry;
use PandaBear\Mlm\Models\Wallet;
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
 * A binary commission whose pairing a reversal partly undid (ADR-025) gives
 * back the matching share of its stored amount (ADR-026): apportioned
 * cumulatively and floored, never recalculated — before posting by reducing
 * what posting pays, after it by a ledger correction of its own — once per
 * commission and reversal.
 */
final class BinaryFinancialCorrectionTest extends DatabaseTestCase
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

    private PlanComponent $component;

    /**
     * @var array<string, VolumeEntry>
     */
    private array $sales = [];

    protected function setUp(): void
    {
        parent::setUp();

        // P > L left > L2 left; P > R right. One pair of 100 pays 100.
        $this->plan = Plan::factory()->create();
        $this->members = $this->members($this->plan->program, 'P', 'L', 'R', 'L2');
        $this->binaryAt($this->members, 'P', 'L', BinarySide::Left);
        $this->binaryAt($this->members, 'P', 'R', BinarySide::Right);
        $this->binaryAt($this->members, 'L', 'L2', BinarySide::Left);
        $this->component = $this->pairingComponent($this->fixedPairing(['amount_per_pair' => '100']), $this->plan);
    }

    /**
     * @return array<string, array{Closure(self, Commission): Commission, CommissionStatus}>
     */
    public static function unpaidStatuses(): array
    {
        return [
            'calculated' => [static fn (self $test, Commission $commission): Commission => $commission, CommissionStatus::Calculated],
            'pending' => [static fn (self $test, Commission $commission): Commission => $test->commissionLifecycle()->markPending($commission), CommissionStatus::Pending],
            'approved' => [static fn (self $test, Commission $commission): Commission => $test->approved($commission), CommissionStatus::Approved],
        ];
    }

    public function test_a_partial_correction_before_posting_is_recorded_and_posting_pays_what_is_left(): void
    {
        $commission = $this->approved($this->january());
        $reversal = $this->undo('l1', '2026-02-05');

        $adjustment = $this->financial($reversal)->adjustments[0];

        // 30 of the 100 paired is undone: 30 of the 100 paid. The fixed
        // strategy is not run again — 70 left would be no whole pair at all.
        $this->assertSame(['-30', CommissionAdjustmentOutcome::Recorded, null, '2026-02-05 00:00:00'], [$adjustment->amount->value(), $adjustment->outcome, $adjustment->ledger_transaction_id, $adjustment->occurred_at->format('Y-m-d H:i:s')]);
        $this->assertSame(['clawback', 'binary-volume-reversal', $reversal->id], [$adjustment->type, $adjustment->source_type, $adjustment->source_id]);
        $this->assertSame([CommissionStatus::Approved, '100', null], [$commission->refresh()->status, $commission->amount->value(), $commission->postedAmount]);
        $this->assertSame([0, 0], [LedgerTransaction::query()->count(), Wallet::query()->count()]);
        $this->assertSame('70', $this->app->make(CommissionNetAmount::class)->of($commission)->value());

        $posted = $this->poster()->post($commission);

        $this->assertSame([CommissionStatus::Posted, '100', '70'], [$posted->status, $posted->amount->value(), $posted->postedAmount?->value()]);
        $this->assertSame('70', $this->walletBalance());
        $this->assertSame('-70', $this->balances()->forAccount($posted->run->sourceAccount)->value());
        $this->assertSame(['-70', '70'], $this->sortedPostings($posted->ledgerTransaction));
        // Replaying the post checks it against what it posted.
        $this->assertSame($posted->ledger_transaction_id, $this->poster()->post($posted)->ledger_transaction_id);
    }

    /**
     * @param  Closure(self, Commission): Commission  $reach
     */
    #[DataProvider('unpaidStatuses')]
    public function test_an_unpaid_commission_keeps_its_status_until_nothing_is_left_and_is_then_cancelled(Closure $reach, CommissionStatus $status): void
    {
        $commission = $reach($this, $this->january());

        $first = $this->financial($this->undo('l1', '2026-02-05'))->adjustments[0];

        $this->assertSame(['-30', CommissionAdjustmentOutcome::Recorded, $status], [$first->amount->value(), $first->outcome, $commission->refresh()->status]);

        $second = $this->financial($this->undo('l2', '2026-03-05'))->adjustments[0];

        $this->assertSame(['-70', CommissionAdjustmentOutcome::Cancelled, null], [$second->amount->value(), $second->outcome, $second->ledger_transaction_id]);
        $this->assertSame(CommissionStatus::Cancelled, $commission->refresh()->status);
        $this->assertSame(['-30 recorded', '-70 cancelled'], $this->adjustmentsOf($commission));
        $this->assertSame([0, 0], [LedgerTransaction::query()->count(), Wallet::query()->count()]);
    }

    public function test_a_partial_correction_of_a_posted_commission_moves_back_only_its_share(): void
    {
        $posted = $this->poster()->post($this->approved($this->january()));
        $original = (array) DB::table('mlm_ledger_transactions')->where('id', $posted->ledger_transaction_id)->first();
        $reversal = $this->undo('l1', '2026-02-05');

        $adjustment = $this->financial($reversal)->adjustments[0];
        $transaction = $adjustment->ledgerTransaction;
        $this->assertNotNull($transaction);

        $this->assertSame(['-30', CommissionAdjustmentOutcome::Adjusted, CommissionStatus::Posted, '100'], [$adjustment->amount->value(), $adjustment->outcome, $posted->refresh()->status, $posted->postedAmount?->value()]);
        $this->assertSame(
            ['commission-adjustment', 'commission-adjustment', "{$posted->id}:{$reversal->id}", "commission.adjust.{$posted->id}.binary-volume-reversal.{$reversal->id}", '2026-02-05 00:00:00', null],
            [$transaction->type, $transaction->source_type, $transaction->source_id, $transaction->idempotency_key, $transaction->occurred_at->format('Y-m-d H:i:s'), $transaction->reversal_of_id],
        );
        $wallet = Wallet::query()->where('member_id', $this->members['P']->id)->sole();
        $this->assertEqualsCanonicalizing([$wallet->account?->id => '-30', $posted->run->source_ledger_account_id => '30'], $this->postingsOf($transaction));
        $this->assertSame(['70', '-70'], [$this->walletBalance(), $this->balances()->forAccount($posted->run->sourceAccount)->value()]);
        // The original posting is history: untouched, and still what the commission says it posted.
        $this->assertEquals($original, (array) DB::table('mlm_ledger_transactions')->where('id', $posted->ledger_transaction_id)->first());
        $this->assertSame($posted->ledger_transaction_id, $this->poster()->post($posted)->ledger_transaction_id);
        $this->assertSame(0, LedgerTransaction::query()->whereNotNull('reversal_of_id')->count());
    }

    public function test_a_posted_commission_wholly_undone_at_once_is_reversed(): void
    {
        $posted = $this->poster()->post($this->approved($this->january()));

        // r1 fed the whole right side of the pair: all of it is undone.
        $adjustment = $this->financial($this->undo('r1', '2026-02-05'))->adjustments[0];
        $reversed = $posted->refresh();

        $this->assertSame(['-100', CommissionAdjustmentOutcome::Reversed, CommissionStatus::Reversed], [$adjustment->amount->value(), $adjustment->outcome, $reversed->status]);
        $this->assertSame($reversed->reversal_ledger_transaction_id, $adjustment->ledger_transaction_id);
        $this->assertSame([$posted->ledger_transaction_id, '2026-02-05 00:00:00'], [$adjustment->ledgerTransaction?->reversal_of_id, $adjustment->ledgerTransaction?->occurred_at->format('Y-m-d H:i:s')]);
        $this->assertSame(['0', '0'], [$this->walletBalance(), $this->balances()->forAccount($posted->run->sourceAccount)->value()]);
        $this->assertSame(0, LedgerTransaction::query()->where('type', 'commission-adjustment')->count());
    }

    public function test_a_posted_commission_undone_in_parts_is_paid_back_in_parts_and_never_reversed_whole(): void
    {
        $posted = $this->poster()->post($this->approved($this->january()));
        $first = $this->undo('l1', '2026-02-05');
        $this->financial($first);
        $second = $this->undo('l2', '2026-03-05');
        $binary = $this->binaryHistory();
        $facts = $this->calculatedFacts($posted);

        $adjustment = $this->financial($second)->adjustments[0];

        $this->assertSame(['-70', CommissionAdjustmentOutcome::Adjusted, CommissionStatus::Posted], [$adjustment->amount->value(), $adjustment->outcome, $posted->refresh()->status]);
        $this->assertSame(['-30 adjusted', '-70 adjusted'], $this->adjustmentsOf($posted));
        $this->assertSame(['0', '0'], [$this->walletBalance(), $this->balances()->forAccount($posted->run->sourceAccount)->value()]);
        $this->assertSame([2, 0], [LedgerTransaction::query()->where('type', 'commission-adjustment')->count(), LedgerTransaction::query()->whereNotNull('reversal_of_id')->count()]);
        $this->assertSame('0', $this->app->make(CommissionNetAmount::class)->of($posted)->value());

        // History is never rewritten: the binary journal, the runs and the
        // commission's calculated facts are as the pairing runs left them.
        $this->assertEquals($binary, $this->binaryHistory());
        $this->assertSame($facts, $this->calculatedFacts($posted));

        // Reversing its whole posting now would take back 100 more.
        try {
            $this->poster()->reverse($posted, CarbonImmutable::parse('2026-04-01'));
            $this->fail('A partly corrected commission was reversed whole.');
        } catch (InvalidCommissionPosting $exception) {
            $this->assertStringContainsString('already partly corrected through the ledger', $exception->getMessage());
        }

        $this->assertSame('0', $this->walletBalance());
    }

    public function test_a_later_full_correction_reverses_what_posting_actually_paid(): void
    {
        $commission = $this->approved($this->january());
        $this->financial($this->undo('l1', '2026-02-05'));
        $posted = $this->poster()->post($commission);
        $this->assertSame(['70', '70'], [$posted->postedAmount?->value(), $this->walletBalance()]);

        $adjustment = $this->financial($this->undo('l2', '2026-03-05'))->adjustments[0];

        $this->assertSame(['-70', CommissionAdjustmentOutcome::Reversed, CommissionStatus::Reversed], [$adjustment->amount->value(), $adjustment->outcome, $posted->refresh()->status]);
        $this->assertSame(['-70', '70'], $this->sortedPostings($adjustment->ledgerTransaction));
        $this->assertSame(['0', '0'], [$this->walletBalance(), $this->balances()->forAccount($posted->run->sourceAccount)->value()]);
        $this->assertSame(['-30 recorded', '-70 reversed'], $this->adjustmentsOf($posted));
    }

    public function test_a_cancelled_commission_is_left_as_it_is(): void
    {
        $cancelled = $this->commissionLifecycle()->cancel($this->january());
        $row = (array) DB::table('mlm_commissions')->where('id', $cancelled->id)->first();

        $adjustment = $this->financial($this->undo('l1', '2026-02-05'))->adjustments[0];

        $this->assertSame(['-30', CommissionAdjustmentOutcome::AlreadyCancelled, null], [$adjustment->amount->value(), $adjustment->outcome, $adjustment->ledger_transaction_id]);
        $this->assertEquals($row, (array) DB::table('mlm_commissions')->where('id', $cancelled->id)->first());
        $this->assertSame(0, LedgerTransaction::query()->count());
    }

    public function test_a_commission_already_reversed_is_not_reversed_again(): void
    {
        $reversed = $this->poster()->reverse($this->poster()->post($this->approved($this->january())), CarbonImmutable::parse('2026-02-02'));
        $ledger = $this->ledgerRows();

        $adjustment = $this->financial($this->undo('l1', '2026-02-05'))->adjustments[0];

        $this->assertSame(['-30', CommissionAdjustmentOutcome::AlreadyReversed, $reversed->reversal_ledger_transaction_id], [$adjustment->amount->value(), $adjustment->outcome, $adjustment->ledger_transaction_id]);
        $this->assertSame(CommissionStatus::Reversed, $reversed->refresh()->status);
        $this->assertSame($ledger, $this->ledgerRows());
    }

    /**
     * @return array<string, array{string, list<string>, bool}>
     */
    public static function cumulativeShares(): array
    {
        return [
            'a millionth, in order' => ['0.000001', ['0', '0', '-0.000001'], false],
            'a millionth, newest reversal first' => ['0.000001', ['0', '0', '-0.000001'], true],
            // Each third on its own floors to 33.333333, which would leave a millionth behind.
            'a hundred, in order' => ['100', ['-33.333333', '-33.333333', '-33.333334'], false],
            'a hundred, newest reversal first' => ['100', ['-33.333333', '-33.333333', '-33.333334'], true],
        ];
    }

    /**
     * @param  list<string>  $expected  each reversal's correction, oldest reversal first
     */
    #[DataProvider('cumulativeShares')]
    public function test_the_stored_amount_is_apportioned_cumulatively_whatever_order_reversals_are_processed_in(string $amount, array $expected, bool $newestFirst): void
    {
        // One pair of 3: left 1 + 1 + 1, right 3. Each left source is
        // reversed on its own day of one run.
        $component = $this->pairingComponent($this->fixedPairing(['pair_quantity' => '3', 'amount_per_pair' => $amount]), Plan::factory()->for($this->plan->program)->create());
        $left = [];

        foreach (['a', 'b', 'c'] as $index => $key) {
            $left[] = $this->sale($this->members['L'], '1', '2026-01-1'.$index, $key);
        }

        $this->sale($this->members['R'], '3', '2026-01-10', 'r');
        $commission = $this->pair($component, '2026-01-01', '2026-02-01')->commissions()->sole();
        $reversals = array_map(fn (VolumeEntry $sale): VolumeEntry => $this->reverse($sale, "{$sale->idempotency_key}-refund", at: CarbonImmutable::parse('2026-02-0'.(5 + array_search($sale, $left, true)))), $left);
        $this->pair($component, '2026-02-01', '2026-03-01');

        $order = $newestFirst ? array_reverse($reversals) : $reversals;
        $amounts = [];

        foreach ($order as $reversal) {
            $amounts[$reversal->id] = $this->financial($reversal)->adjustments[0]->amount->value();
        }

        $this->assertSame($expected, array_map(static fn (VolumeEntry $reversal): string => $amounts[$reversal->id], $reversals));
        $this->assertSame((int) $commission->amount_millionths, -(int) CommissionAdjustment::query()->sum('amount_millionths'));
        $this->assertSame(CommissionStatus::Cancelled, $commission->refresh()->status);
    }

    public function test_a_proportional_commission_is_corrected_from_its_stored_rounded_amount(): void
    {
        // 0.7 paired × 0.000005 = 0.0000035, rounded half up: 0.000004.
        $component = $this->pairingComponent($this->proportionalPairing(['pair_quantity' => '0.7', 'unit_amount' => '0.000005', 'rounding' => 'half_up']), Plan::factory()->for($this->plan->program)->create(), 'binary.pairing.proportional');
        $small = $this->sale($this->members['L'], '0.1', '2026-01-10', 'l1');
        $large = $this->sale($this->members['L'], '0.6', '2026-01-11', 'l2');
        $this->sale($this->members['R'], '0.7', '2026-01-10', 'r1');
        $commission = $this->pair($component, '2026-01-01', '2026-02-01')->commissions()->sole();
        $this->assertSame('0.000004', $commission->amount->value());
        $first = $this->reverse($small, 'l1-refund', at: CarbonImmutable::parse('2026-02-05'));
        $this->pair($component, '2026-02-01', '2026-03-01');

        // ⌊4 × 0.1 ÷ 0.7⌋ = 0 millionths. Rerunning the strategy on the 0.6
        // left would pay 0.000003 — a correction of 0.000001 it never makes.
        $zero = $this->financial($first)->adjustments[0];

        $this->assertSame(['0', CommissionAdjustmentOutcome::Recorded, null, CommissionStatus::Calculated], [$zero->amount->value(), $zero->outcome, $zero->ledger_transaction_id, $commission->refresh()->status]);
        $this->assertSame(['0', '0', '0.1', '0.7'], [$zero->trace['financial']['target_before'], $zero->trace['financial']['target_after'], $zero->trace['binary']['invalidated_after'], $zero->trace['binary']['total_consumed_quantity']]);
        // Recorded all the same: the reversal is processed, and replays find it.
        $this->assertSame($zero->id, $this->financial($first)->adjustments[0]->id);

        $second = $this->reverse($large, 'l2-refund', at: CarbonImmutable::parse('2026-03-05'));
        $this->pair($component, '2026-03-01', '2026-04-01');

        $this->assertSame(['-0.000004', 'cancelled'], [($last = $this->financial($second)->adjustments[0])->amount->value(), $last->outcome->value]);
        $this->assertSame(['0 recorded', '-0.000004 cancelled'], $this->adjustmentsOf($commission));
    }

    public function test_a_pairing_that_earned_nothing_after_rounding_needs_no_financial_correction(): void
    {
        $component = $this->pairingComponent($this->proportionalPairing(['pair_quantity' => '0.1', 'unit_amount' => '0.000001']), Plan::factory()->for($this->plan->program)->create(), 'binary.pairing.proportional');
        $sale = $this->sale($this->members['L'], '0.4', '2026-01-10', 'l1');
        $this->sale($this->members['R'], '0.4', '2026-01-10', 'r1');
        $this->pair($component, '2026-01-01', '2026-02-01');
        $reversal = $this->reverse($sale, 'l1-refund', at: CarbonImmutable::parse('2026-02-05'));
        $this->pair($component, '2026-02-01', '2026-03-01');
        $this->assertSame(1, BinaryPairingCorrection::query()->whereNull('commission_id')->count());

        $result = $this->financial($reversal);

        $this->assertSame([[], $sale->id, $reversal->id], [$result->adjustments, $result->originalVolumeEntryId, $result->reversalVolumeEntryId]);
        $this->assertSame([0, 0], [CommissionAdjustment::query()->count(), LedgerTransaction::query()->count()]);
    }

    public function test_a_reversal_is_found_once_the_pairing_run_has_recorded_it(): void
    {
        $commission = $this->january();
        $reversal = $this->reverse($this->sales['l1'], 'l1-refund', at: CarbonImmutable::parse('2026-02-05'));

        // Not yet taken in: there is nothing to correct, and nothing is faked.
        $this->assertSame([], $this->financial($reversal)->adjustments);
        $this->assertSame(0, CommissionAdjustment::query()->count());

        $this->pair($this->component, '2026-02-01', '2026-03-01');

        $this->assertSame(['-30 recorded'], array_map(static fn (CommissionAdjustment $adjustment): string => "{$adjustment->amount->value()} {$adjustment->outcome->value}", $this->financial($reversal)->adjustments));
        $this->assertSame('-30', CommissionAdjustment::query()->where('commission_id', $commission->id)->sole()->amount->value());
    }

    public function test_processing_again_returns_the_same_adjustments_and_moves_nothing(): void
    {
        $this->poster()->post($this->approved($this->january()));
        $reversal = $this->undo('l1', '2026-02-05');
        $first = $this->financial($reversal);
        $state = [$this->ledgerRows(), DB::table('mlm_commissions')->orderBy('id')->get()->all(), DB::table('mlm_commission_adjustments')->orderBy('id')->get()->all()];

        $again = $this->financial($reversal);

        $this->assertSame(array_map(static fn (CommissionAdjustment $adjustment): string => $adjustment->id, $first->adjustments), array_map(static fn (CommissionAdjustment $adjustment): string => $adjustment->id, $again->adjustments));
        $this->assertEquals($state, [$this->ledgerRows(), DB::table('mlm_commissions')->orderBy('id')->get()->all(), DB::table('mlm_commission_adjustments')->orderBy('id')->get()->all()]);
        $this->assertSame('70', $this->walletBalance());
    }

    public function test_the_adjustment_explains_its_share(): void
    {
        $commission = $this->approved($this->january());
        $this->financial($this->undo('l1', '2026-02-05'));
        $reversal = $this->undo('l2', '2026-03-05');
        $correction = BinaryPairingCorrection::query()->where('reversal_volume_entry_id', $reversal->id)->sole();

        $adjustment = $this->financial($reversal)->adjustments[0];

        $this->assertSame([
            'binary' => [
                'correction_ids' => [$correction->id],
                'invalidated_after' => '100',
                'invalidated_before' => '30',
                'pairing_result_id' => $correction->binary_pairing_result_id,
                'plan_component_id' => $this->component->id,
                'total_consumed_quantity' => '100',
            ],
            'commission' => ['id' => $commission->id, 'member_id' => $this->members['P']->id, 'posted_amount' => null, 'status_before' => 'approved'],
            'financial' => ['adjustment_amount' => '-70', 'allocation_policy' => 'cumulative-floor', 'original_commission_amount' => '100', 'target_after' => '100', 'target_before' => '30'],
            'ledger_transaction_id' => null,
            'outcome' => 'cancelled',
            'reason' => 'binary-source-reversal',
            'source' => ['original_volume_entry_id' => $this->sales['l2']->id, 'reversal_effective_at' => '2026-03-05 00:00:00', 'reversal_volume_entry_id' => $reversal->id],
        ], $adjustment->trace);
    }

    public function test_several_corrections_of_one_pairing_by_one_reversal_make_one_adjustment(): void
    {
        $commission = $this->january();
        $reversal = $this->undo('l1', '2026-02-05');
        $journaled = BinaryPairingCorrection::query()->sole();
        // Should one reversal undo two allocations of one pairing, they are
        // one correction of its commission: here a second row, written raw,
        // undoes 20 of l2's allocation too.
        $second = strtolower((string) Str::ulid());
        $row = (array) DB::table('mlm_binary_pairing_corrections')->where('id', $journaled->id)->first();
        DB::table('mlm_binary_pairing_corrections')->insert([
            ...$row,
            'id' => $second,
            'invalidated_allocation_id' => DB::table('mlm_binary_pairing_allocations')->where('binary_pairing_result_id', $journaled->binary_pairing_result_id)->where('quantity_millionths', 70_000_000)->value('id'),
            'quantity_millionths' => 20_000_000,
        ]);

        $result = $this->financial($reversal);

        $ids = [$journaled->id, $second];
        sort($ids, SORT_STRING);
        $this->assertCount(1, $result->adjustments);
        $this->assertSame(['-50', $ids, '50'], [$result->adjustments[0]->amount->value(), $result->adjustments[0]->trace['binary']['correction_ids'], $result->adjustments[0]->trace['binary']['invalidated_after']]);
        $this->assertSame(1, CommissionAdjustment::query()->where('commission_id', $commission->id)->count());
    }

    public function test_each_component_corrects_its_own_commission_from_its_own_pairing(): void
    {
        // A second component pays 4 per pair of 50: two pairs, 8.
        $other = $this->pairingComponent($this->fixedPairing(['pair_quantity' => '50', 'amount_per_pair' => '4']), Plan::factory()->for($this->plan->program)->create());
        $main = $this->january('main:jan');
        $second = $this->pair($other, '2026-01-01', '2026-02-01', 'other:jan')->commissions()->sole();
        $reversal = $this->reverse($this->sales['l1'], 'l1-refund', at: CarbonImmutable::parse('2026-02-05'));
        $this->pair($this->component, '2026-02-01', '2026-03-01', 'main:feb');
        $this->pair($other, '2026-02-01', '2026-03-01', 'other:feb');

        $result = $this->financial($reversal);

        // 30 of the 100 each paired: 30 of 100, and 2.4 of 8.
        $shares = [];

        foreach ($result->adjustments as $adjustment) {
            $shares[$adjustment->commission_id] = [$adjustment->amount->value(), $adjustment->trace['binary']['plan_component_id']];
        }

        $this->assertEquals([$main->id => ['-30', $this->component->id], $second->id => ['-2.4', $other->id]], $shares);
    }

    public function test_a_commission_is_not_posted_while_a_correction_of_it_is_unresolved(): void
    {
        $commission = $this->approved($this->january());
        $reversal = $this->undo('l1', '2026-02-05');

        try {
            $this->poster()->post($commission);
            $this->fail('A commission with an unresolved binary correction was posted.');
        } catch (UnresolvedBinaryCorrection $exception) {
            $this->assertStringContainsString("[{$reversal->id}]", $exception->getMessage());
        }

        $this->assertSame([CommissionStatus::Approved, 0], [$commission->refresh()->status, LedgerTransaction::query()->count()]);

        $this->financial($reversal);
        $posted = $this->poster()->post($commission);

        $this->assertSame(['70', '70'], [$posted->postedAmount?->value(), $this->walletBalance()]);
    }

    public function test_an_approved_commission_with_nothing_left_is_never_posted_for_zero(): void
    {
        $commission = $this->approved($this->january());
        // A raw write: adjustments that took everything, yet it was left approved.
        DB::table('mlm_commission_adjustments')->insert([
            'id' => strtolower((string) Str::ulid()), 'program_id' => $commission->program_id, 'commission_id' => $commission->id,
            'type' => 'clawback', 'source_type' => 'manual', 'source_id' => 'M-1', 'amount_millionths' => -100_000_000,
            'occurred_at' => '2026-02-01 00:00:00', 'outcome' => 'recorded', 'ledger_transaction_id' => null, 'trace' => '{}',
            'created_at' => '2026-02-01 00:00:00', 'updated_at' => '2026-02-01 00:00:00',
        ]);

        try {
            $this->poster()->post($commission);
            $this->fail('A commission was posted for nothing.');
        } catch (InvalidCommissionPosting $exception) {
            $this->assertStringContainsString('no amount to post', $exception->getMessage());
        }

        $this->assertSame([CommissionStatus::Approved, 0], [$commission->refresh()->status, LedgerTransaction::query()->count()]);
    }

    public function test_one_call_corrects_every_commission_it_finds_or_none(): void
    {
        // l1 250 pairs in January and in February: two commissions of 100.
        $sale = $this->sale($this->members['L'], '250', '2026-01-10', 'l1');
        $this->sale($this->members['R'], '100', '2026-01-10', 'r1');
        $january = $this->poster()->post($this->approved($this->pair($this->component, '2026-01-01', '2026-02-01')->commissions()->sole()));
        $this->sale($this->members['R'], '100', '2026-02-10', 'r2');
        $february = $this->poster()->post($this->approved($this->pair($this->component, '2026-02-01', '2026-03-01')->commissions()->sole()));
        $reversal = $this->reverse($sale, 'l1-refund', at: CarbonImmutable::parse('2026-03-05'));
        $this->pair($this->component, '2026-03-01', '2026-04-01');
        $state = [$this->ledgerRows(), DB::table('mlm_commissions')->orderBy('id')->get()->all()];
        $inserts = 0;

        DB::listen(static function (QueryExecuted $query) use (&$inserts): void {
            if (str_starts_with($query->sql, 'insert into') && str_contains($query->sql, 'mlm_commission_adjustments') && ++$inserts === 2) {
                throw new RuntimeException('The second adjustment could not be written.');
            }
        });

        try {
            $this->financial($reversal);
            $this->fail('A call that failed half way kept its first correction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The second adjustment could not be written.', $exception->getMessage());
        }

        $this->assertSame(0, CommissionAdjustment::query()->count());
        $this->assertEquals($state, [$this->ledgerRows(), DB::table('mlm_commissions')->orderBy('id')->get()->all()]);
        $this->assertSame('200', $this->walletBalance());
        $this->assertSame([CommissionStatus::Posted, CommissionStatus::Posted], [$january->refresh()->status, $february->refresh()->status]);
    }

    public function test_a_reversal_is_read_as_stored_and_held_to_the_volume_history(): void
    {
        $this->january();
        $reversal = $this->undo('l1', '2026-02-05');

        try {
            $this->financial($this->sales['l1']);
            $this->fail('An original entry was processed as a reversal.');
        } catch (InvalidCommissionAdjustment $exception) {
            $this->assertStringContainsString('is an original entry, not a reversal', $exception->getMessage());
        }

        // Changed in memory, the request is still the stored reversal.
        $reversal->reversal_of_id = $this->sales['l2']->id;
        $reversal->effective_at = CarbonImmutable::parse('2030-01-01');

        $adjustment = $this->financial($reversal)->adjustments[0];

        $this->assertSame(['-30', '2026-02-05 00:00:00', $this->sales['l1']->id], [$adjustment->amount->value(), $adjustment->occurred_at->format('Y-m-d H:i:s'), $adjustment->trace['source']['original_volume_entry_id']]);
    }

    public function test_a_reversal_reaching_many_commissions_reads_its_journal_in_sets(): void
    {
        // l1 300 pairs in January, February and March: three commissions.
        $sale = $this->sale($this->members['L'], '300', '2026-01-10', 'l1');

        foreach (['01', '02', '03'] as $month) {
            $this->sale($this->members['R'], '100', "2026-{$month}-10", "r{$month}");
            $this->pair($this->component, "2026-{$month}-01", CarbonImmutable::parse("2026-{$month}-01")->addMonth()->format('Y-m-d'));
        }

        $reversal = $this->reverse($sale, 'l1-refund', at: CarbonImmutable::parse('2026-04-05'));
        $this->pair($this->component, '2026-04-01', '2026-05-01');
        $reads = [];

        DB::listen(static function (QueryExecuted $query) use (&$reads): void {
            foreach (['mlm_binary_pairing_corrections', 'mlm_binary_pairing_results'] as $table) {
                if (str_starts_with($query->sql, 'select') && str_contains($query->sql, $table)) {
                    $reads[$table] = ($reads[$table] ?? 0) + 1;
                }
            }
        });

        $result = $this->financial($reversal);

        $this->assertSame(3, $result->count(CommissionAdjustmentOutcome::Cancelled));
        // The reversal's corrections, their pairings' whole history, their
        // results: one read each, however many commissions.
        $this->assertSame(['mlm_binary_pairing_corrections' => 2, 'mlm_binary_pairing_results' => 1], $reads);
    }

    /**
     * One pair in January: left l1 30 + l2 70, right r1 100 — P earns 100.
     */
    private function january(string $key = 'pair:2026-01-01'): Commission
    {
        $this->sales['l1'] = $this->sale($this->members['L'], '30', '2026-01-10', 'l1');
        $this->sales['l2'] = $this->sale($this->members['L'], '70', '2026-01-11', 'l2');
        $this->sales['r1'] = $this->sale($this->members['R'], '100', '2026-01-10', 'r1');

        return $this->pair($this->component, '2026-01-01', '2026-02-01', $key)->commissions()->sole();
    }

    /**
     * Reverses the sale, and runs the month its reversal falls in — the
     * run that undoes its pairs — after any month still to run.
     */
    private function undo(string $sale, string $at): VolumeEntry
    {
        $reversal = $this->reverse($this->sales[$sale], "{$sale}-refund", at: CarbonImmutable::parse($at));
        $through = CarbonImmutable::parse((string) DB::table('mlm_binary_pairing_cursors')->where('plan_component_id', $this->component->id)->value('through_at'));

        while ($through->lessThanOrEqualTo(CarbonImmutable::parse($at))) {
            $this->pair($this->component, $through->format('Y-m-d'), $through->addMonth()->format('Y-m-d'));
            $through = $through->addMonth();
        }

        return $reversal;
    }

    private function financial(VolumeEntry $reversal): CommissionAdjustmentResult
    {
        return $this->app->make(CommissionAdjustmentEngine::class)->processBinaryReversal($reversal);
    }

    private function walletBalance(): string
    {
        return $this->balances()->forWallet(Wallet::query()->where('member_id', $this->members['P']->id)->sole())->value();
    }

    /**
     * @return list<string> the transaction's posting amounts, ascending
     */
    private function sortedPostings(?LedgerTransaction $transaction): array
    {
        $this->assertNotNull($transaction);
        $amounts = array_values($this->postingsOf($transaction));
        sort($amounts, SORT_STRING);

        return $amounts;
    }

    /**
     * The commission's adjustments, oldest reversal first: "amount outcome".
     *
     * @return list<string>
     */
    private function adjustmentsOf(Commission $commission): array
    {
        return CommissionAdjustment::query()->where('commission_id', $commission->id)->orderBy('occurred_at')->orderBy('id')->get()
            ->map(static fn (CommissionAdjustment $adjustment): string => "{$adjustment->amount->value()} {$adjustment->outcome->value}")
            ->all();
    }

    /**
     * What a calculation stored, which no correction may change.
     *
     * @return array<string, mixed>
     */
    private function calculatedFacts(Commission $commission): array
    {
        $row = (array) DB::table('mlm_commissions')->where('id', $commission->id)->first();

        return array_intersect_key($row, array_flip(['calculation_run_id', 'program_id', 'member_id', 'candidate_key', 'currency', 'amount_millionths', 'earned_at', 'trace']));
    }

    /**
     * Every binary table and run, exactly.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function binaryHistory(): array
    {
        return array_diff_key($this->pairingState(), ['mlm_commissions' => true]);
    }
}
