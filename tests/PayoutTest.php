<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PandaBear\Mlm\Commission\CommissionAdjustmentEngine;
use PandaBear\Mlm\Commission\CommissionStatus;
use PandaBear\Mlm\Exceptions\ConflictingPayoutRequest;
use PandaBear\Mlm\Exceptions\CorruptPayoutRequest;
use PandaBear\Mlm\Exceptions\ImmutableCalculationRecord;
use PandaBear\Mlm\Exceptions\InsufficientPayoutBalance;
use PandaBear\Mlm\Exceptions\InvalidPayoutRequest;
use PandaBear\Mlm\Exceptions\InvalidPayoutTransition;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\LedgerTransaction;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\PayoutRequest;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Models\Wallet;
use PandaBear\Mlm\Payout\PayoutRequestStatus;
use PandaBear\Mlm\Tests\Concerns\BuildsCommissions;
use PandaBear\Mlm\Tests\Concerns\BuildsFixedCommissions;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;
use PandaBear\Mlm\Tests\Concerns\BuildsLedgers;
use PandaBear\Mlm\Tests\Concerns\BuildsPayouts;
use PandaBear\Mlm\Tests\Concerns\BuildsPlanDefinitions;
use PandaBear\Mlm\Tests\Concerns\RecordsVolume;
use RuntimeException;

/**
 * Payout spends wallet value (ADR-030): a request moves no money, approval
 * reserves the amount from the wallet to the settlement account under the
 * wallet's lock, settlement records the external reference and moves
 * nothing more, failure reverses the reservation — and commissions are
 * never touched, nor marked paid.
 */
final class PayoutTest extends DatabaseTestCase
{
    use BuildsCommissions;
    use BuildsFixedCommissions;
    use BuildsGenealogies;
    use BuildsLedgers;
    use BuildsPayouts;
    use BuildsPlanDefinitions;
    use RecordsVolume;

    private Program $program;

    /**
     * @var array<string, Member>
     */
    private array $members;

    protected function setUp(): void
    {
        parent::setUp();

        $this->program = Program::factory()->create();
        $this->members = $this->members($this->program, 'ALICE', 'BOB');
        $this->fundedWallet($this->members['ALICE'], '100');
        $this->settlementAccount($this->members['ALICE']);
    }

    public function test_a_request_is_recorded_without_moving_money(): void
    {
        $ledger = $this->ledgerRows();

        $request = $this->payoutRequest($this->members['ALICE'], '70');

        $this->assertSame([PayoutRequestStatus::Requested, '70', 'IDR', 'bank-account', 'dest:ALICE', '2026-03-01 10:00:00'], [
            $request->status, $request->amount->value(), $request->currency, $request->destination_type, $request->destination_reference, $request->requested_at->format('Y-m-d H:i:s'),
        ]);
        $this->assertSame([$this->program->id, $this->members['ALICE']->id], [$request->program_id, $request->member_id]);
        $this->assertTrue($request->wallet->member->is($this->members['ALICE']));
        $this->assertSame($ledger, $this->ledgerRows());
        $this->assertSame('100', $this->walletBalance($this->members['ALICE']));
        // More than the wallet holds may be requested: approval decides.
        $this->assertSame('500', $this->payoutRequest($this->members['ALICE'], '500', 'payout:big')->amount->value());
    }

    public function test_the_same_key_returns_the_request_and_other_facts_are_refused(): void
    {
        $request = $this->payoutRequest($this->members['ALICE'], '70');

        $this->assertTrue($request->is($this->payoutRequest($this->members['ALICE'], '70')));

        try {
            $this->payoutRequest($this->members['ALICE'], '71');
            $this->fail('A key was reused for another amount.');
        } catch (ConflictingPayoutRequest $exception) {
            $this->assertStringContainsString('this request differs in amount', $exception->getMessage());
        }

        $this->assertSame(1, PayoutRequest::query()->count());
    }

    public function test_amounts_destinations_and_accounts_are_checked_before_anything_is_recorded(): void
    {
        $bobsWallet = $this->fundedWallet($this->members['BOB'], '10', 'fund:bob');
        $other = Member::factory()->create();
        $this->fundedWallet($other, '10', 'fund:other');

        foreach ([
            'strictly positive' => fn () => $this->payoutRequest($this->members['ALICE'], '0', 'k1'),
            'strictly positive and at most' => fn () => $this->payoutRequest($this->members['ALICE'], '-5', 'k2'),
            'more than 6 decimal places' => fn () => $this->payoutRequest($this->members['ALICE'], '0.0000001', 'k3'),
            'belongs to another member' => fn () => $this->payouts()->request($this->members['ALICE'], $bobsWallet, $this->settlementAccount($this->members['ALICE']), '1', 'bank-account', 'd', now(), 'k4'),
            'belongs to another program' => fn () => $this->payouts()->request($this->members['ALICE'], Wallet::query()->where('member_id', $other->id)->sole(), $this->settlementAccount($this->members['ALICE']), '1', 'bank-account', 'd', now(), 'k5'),
            'is in USD, the wallet in IDR' => fn () => $this->payoutRequest($this->members['ALICE'], '1', 'k6', $this->settlementAccount($this->members['ALICE'], 'USD')),
            'is a wallet\'s account, not a system account' => fn () => $this->payoutRequest($this->members['ALICE'], '1', 'k7', $this->walletAccount($this->members['BOB'])),
            'settlement account [' => fn () => $this->payoutRequest($this->members['ALICE'], '1', 'k8', $this->settlementAccount($other)),
            'a destination type is' => fn () => $this->payouts()->request($this->members['ALICE'], Wallet::query()->where('member_id', $this->members['ALICE']->id)->sole(), $this->settlementAccount($this->members['ALICE']), '1', 'Bank Account', 'd', now(), 'k9'),
        ] as $reason => $request) {
            try {
                $request();
                $this->fail("An invalid request was recorded: {$reason}.");
            } catch (InvalidPayoutRequest $exception) {
                $this->assertStringContainsString($reason, $exception->getMessage());
            }
        }

        $this->assertSame(0, PayoutRequest::query()->count());
    }

    public function test_approval_reserves_the_amount_from_the_wallet_to_the_settlement_account(): void
    {
        $request = $this->payoutRequest($this->members['ALICE'], '70');
        $settlement = $request->settlementAccount;

        $approved = $this->payouts()->approve($request, CarbonImmutable::parse('2026-03-02 09:00:00'));

        $reservation = $approved->reservation;
        $this->assertNotNull($reservation);
        $this->assertSame([PayoutRequestStatus::Approved, '2026-03-02 09:00:00'], [$approved->status, $approved->approved_at?->format('Y-m-d H:i:s')]);
        $this->assertSame(['payout-reservation', 'payout-request', $request->id, "payout.reserve.{$request->id}", '2026-03-02 09:00:00', null], [
            $reservation->type, $reservation->source_type, $reservation->source_id, $reservation->idempotency_key, $reservation->occurred_at->format('Y-m-d H:i:s'), $reservation->reversal_of_id,
        ]);
        $this->assertEqualsCanonicalizing([$this->walletAccount($this->members['ALICE'])->id => '-70', $settlement->id => '70'], $this->postingsOf($reservation));
        $this->assertSame(['30', '70'], [$this->walletBalance($this->members['ALICE']), $this->balances()->forAccount($settlement)->value()]);

        // Approving again moves nothing more.
        $this->assertSame($approved->reservation_ledger_transaction_id, $this->payouts()->approve($request, CarbonImmutable::parse('2026-03-05'))->reservation_ledger_transaction_id);
        $this->assertSame(1, LedgerTransaction::query()->where('type', 'payout-reservation')->count());
    }

    public function test_approval_needs_the_whole_amount_in_the_wallet_and_later_approvals_see_what_is_left(): void
    {
        $first = $this->payoutRequest($this->members['ALICE'], '60', 'payout:a');
        $second = $this->payoutRequest($this->members['ALICE'], '50', 'payout:b');
        $exact = $this->payoutRequest($this->members['ALICE'], '40', 'payout:c');

        $this->payouts()->approve($first, now());
        $ledger = $this->ledgerRows();

        try {
            $this->payouts()->approve($second, now());
            $this->fail('A payout overspent the wallet.');
        } catch (InsufficientPayoutBalance $exception) {
            $this->assertStringContainsString('holds 40', $exception->getMessage());
        }

        $this->assertSame([PayoutRequestStatus::Requested, $ledger], [$second->refresh()->status, $this->ledgerRows()]);

        // Exactly what is left is enough.
        $this->assertSame(PayoutRequestStatus::Approved, $this->payouts()->approve($exact, now())->status);
        $this->assertSame('0', $this->walletBalance($this->members['ALICE']));
    }

    public function test_a_failed_approval_reserves_nothing(): void
    {
        $request = $this->payoutRequest($this->members['ALICE'], '70');
        $ledger = $this->ledgerRows();
        DB::listen(static function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'update') && str_contains($query->sql, 'mlm_payout_requests')) {
                throw new RuntimeException('The status could not be written.');
            }
        });

        try {
            $this->payouts()->approve($request, now());
            $this->fail('The approval survived its failure.');
        } catch (RuntimeException) {
        }

        $this->assertSame([PayoutRequestStatus::Requested, $ledger], [$request->refresh()->status, $this->ledgerRows()]);
    }

    public function test_a_request_is_processed_and_settled_without_moving_money_again(): void
    {
        $request = $this->payouts()->approve($this->payoutRequest($this->members['ALICE'], '40'), now());
        $ledger = $this->ledgerRows();

        $processing = $this->payouts()->startProcessing($request, CarbonImmutable::parse('2026-03-03 08:00:00'));
        $settled = $this->payouts()->settle($request, 'BANK-TRX-1', CarbonImmutable::parse('2026-03-04 12:00:00'));

        $this->assertSame([PayoutRequestStatus::Processing, '2026-03-03 08:00:00'], [$processing->status, $processing->processing_at?->format('Y-m-d H:i:s')]);
        $this->assertSame([PayoutRequestStatus::Settled, 'BANK-TRX-1', '2026-03-04 12:00:00'], [$settled->status, $settled->settlement_reference, $settled->settled_at?->format('Y-m-d H:i:s')]);
        $this->assertSame($ledger, $this->ledgerRows());
        // A partial payout leaves the rest in the wallet.
        $this->assertSame('60', $this->walletBalance($this->members['ALICE']));

        $this->assertTrue($settled->is($this->payouts()->settle($request, 'BANK-TRX-1', CarbonImmutable::parse('2026-03-04 12:00:00'))));

        foreach ([
            fn () => $this->payouts()->settle($request, 'BANK-TRX-2', CarbonImmutable::parse('2026-03-04 12:00:00')),
            fn () => $this->payouts()->settle($request, 'BANK-TRX-1', CarbonImmutable::parse('2026-03-05 12:00:00')),
        ] as $conflicting) {
            try {
                $conflicting();
                $this->fail('A settlement was replayed with other facts.');
            } catch (ConflictingPayoutRequest) {
            }
        }

        $this->expectException(InvalidPayoutTransition::class);
        $this->expectExceptionMessage('is settled and cannot become failed');

        $this->payouts()->fail($request, 'provider-rejected', now());
    }

    public function test_a_settlement_reference_settles_one_request_and_steps_follow_the_lifecycle(): void
    {
        $first = $this->payouts()->startProcessing($this->payouts()->approve($this->payoutRequest($this->members['ALICE'], '10', 'payout:a'), now()), now());
        $second = $this->payouts()->startProcessing($this->payouts()->approve($this->payoutRequest($this->members['ALICE'], '10', 'payout:b'), now()), now());
        $requested = $this->payoutRequest($this->members['ALICE'], '10', 'payout:c');
        $this->payouts()->settle($first, 'BANK-TRX-1', now());

        foreach ([
            [fn () => $this->payouts()->settle($second, 'BANK-TRX-1', now()), ConflictingPayoutRequest::class, 'already settled payout request'],
            [fn () => $this->payouts()->settle($requested, 'BANK-TRX-9', now()), InvalidPayoutTransition::class, 'is requested and cannot become settled'],
            [fn () => $this->payouts()->startProcessing($requested, now()), InvalidPayoutTransition::class, 'is requested and cannot become processing'],
            [fn () => $this->payouts()->fail($requested, 'nope', now()), InvalidPayoutTransition::class, 'is requested and cannot become failed'],
        ] as [$step, $exception, $reason]) {
            try {
                $step();
                $this->fail("A step was taken out of order: {$reason}.");
            } catch (ConflictingPayoutRequest|InvalidPayoutTransition $caught) {
                $this->assertInstanceOf($exception, $caught);
                $this->assertStringContainsString($reason, $caught->getMessage());
            }
        }
    }

    public function test_a_failure_reverses_the_reservation_exactly_and_restores_the_wallet(): void
    {
        $approved = $this->payouts()->approve($this->payoutRequest($this->members['ALICE'], '70', 'payout:a'), now());
        $processing = $this->payouts()->startProcessing($this->payouts()->approve($this->payoutRequest($this->members['ALICE'], '30', 'payout:b'), now()), now());
        $this->assertSame('0', $this->walletBalance($this->members['ALICE']));

        $failed = $this->payouts()->fail($approved, 'provider-rejected', CarbonImmutable::parse('2026-03-06 10:00:00'));
        $this->payouts()->fail($processing, 'account-closed', CarbonImmutable::parse('2026-03-06 11:00:00'));

        $refund = $failed->refund;
        $this->assertNotNull($refund);
        $this->assertSame([PayoutRequestStatus::Failed, 'provider-rejected', '2026-03-06 10:00:00'], [$failed->status, $failed->failure_reason, $failed->failed_at?->format('Y-m-d H:i:s')]);
        $this->assertSame([$failed->reservation_ledger_transaction_id, "payout.refund.{$failed->id}", '2026-03-06 10:00:00'], [$refund->reversal_of_id, $refund->idempotency_key, $refund->occurred_at->format('Y-m-d H:i:s')]);
        $this->assertEqualsCanonicalizing([$this->walletAccount($this->members['ALICE'])->id => '70', $failed->settlement_ledger_account_id => '-70'], $this->postingsOf($refund));
        $this->assertSame(['100', '0'], [$this->walletBalance($this->members['ALICE']), $this->balances()->forAccount($failed->settlementAccount)->value()]);

        // Failing again refunds nothing more; other facts are refused.
        $ledger = $this->ledgerRows();
        $this->assertSame($failed->refund_ledger_transaction_id, $this->payouts()->fail($approved, 'provider-rejected', CarbonImmutable::parse('2026-03-06 10:00:00'))->refund_ledger_transaction_id);
        $this->assertSame([2, $ledger, '100'], [LedgerTransaction::query()->whereNotNull('reversal_of_id')->count(), $this->ledgerRows(), $this->walletBalance($this->members['ALICE'])]);

        try {
            $this->payouts()->fail($approved, 'another-reason', CarbonImmutable::parse('2026-03-06 10:00:00'));
            $this->fail('A failure was replayed with another reason.');
        } catch (ConflictingPayoutRequest $exception) {
            $this->assertStringContainsString('differs in reason', $exception->getMessage());
        }

        $this->expectException(InvalidPayoutTransition::class);
        $this->expectExceptionMessage('is failed and cannot become settled');

        $this->payouts()->settle($approved, 'BANK-TRX-1', now());
    }

    public function test_a_request_is_cancelled_before_approval_only(): void
    {
        $request = $this->payoutRequest($this->members['ALICE'], '70');
        $ledger = $this->ledgerRows();

        $cancelled = $this->payouts()->cancel($request, CarbonImmutable::parse('2026-03-02'), 'member-withdrew');

        $this->assertSame([PayoutRequestStatus::Cancelled, '2026-03-02 00:00:00', 'member-withdrew', $ledger], [$cancelled->status, $cancelled->cancelled_at?->format('Y-m-d H:i:s'), $cancelled->failure_reason, $this->ledgerRows()]);
        $this->assertTrue($cancelled->is($this->payouts()->cancel($request, CarbonImmutable::parse('2026-03-02'), 'member-withdrew')));

        try {
            $this->payouts()->approve($request, now());
            $this->fail('A cancelled request was approved.');
        } catch (InvalidPayoutTransition) {
        }

        $this->expectException(InvalidPayoutTransition::class);
        $this->payouts()->cancel($this->payouts()->approve($this->payoutRequest($this->members['ALICE'], '10', 'payout:b'), now()), now());
    }

    public function test_a_stored_request_its_ledger_contradicts_is_refused(): void
    {
        $approved = $this->payouts()->approve($this->payoutRequest($this->members['ALICE'], '70'), now());

        // A raw write: the reservation moves another amount.
        DB::table('mlm_ledger_postings')->where('ledger_transaction_id', $approved->reservation_ledger_transaction_id)->where('amount_millionths', '<', 0)->update(['amount_millionths' => -60_000_000]);

        try {
            $this->payouts()->approve($approved, now());
            $this->fail('A corrupt reservation was accepted.');
        } catch (CorruptPayoutRequest $exception) {
            $this->assertStringContainsString('does not move exactly its amount', $exception->getMessage());
        }

        // A raw write: settled, though never processed.
        $request = $this->payoutRequest($this->members['ALICE'], '1', 'payout:b');
        DB::table('mlm_payout_requests')->where('id', $request->id)->update(['status' => 'settled', 'settlement_reference' => 'X', 'settled_at' => now()]);

        $this->expectException(CorruptPayoutRequest::class);
        $this->expectExceptionMessage('it has no reservation');

        $this->payouts()->settle($request, 'X', now());
    }

    public function test_requests_are_written_by_the_manager_alone_and_wallets_keep_no_balance(): void
    {
        $request = $this->payoutRequest($this->members['ALICE'], '70');

        foreach ([
            static fn () => PayoutRequest::query()->forceCreate([]),
            static fn () => $request->forceFill(['amount_millionths' => 1])->save(),
            static fn () => $request->delete(),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A payout request was written through Eloquent.');
            } catch (ImmutableCalculationRecord) {
            }
        }

        $this->assertSame([], array_values(array_filter(Schema::getColumnListing('mlm_wallets'), static fn (string $column): bool => str_contains($column, 'balance') || str_contains($column, 'reserved'))));
        $this->assertNotContains('paid', array_map(static fn (CommissionStatus $status): string => $status->value, CommissionStatus::cases()));
    }

    public function test_a_posted_commission_pays_out_through_the_wallet_and_is_never_touched(): void
    {
        // BOB's sale pays ALICE 100, posted to her wallet.
        $plan = Plan::factory()->for($this->program)->create();
        $this->sponsorAt($this->members['BOB'], $this->members['ALICE'], '2026-01-01 00:00:00');
        $sale = $this->sale($this->members['BOB'], '150', '2026-01-10', 'order:1');
        $component = $this->fixedComponent('direct-sponsor.fixed', $this->directParameters(['amount' => '100']), $plan);
        $commission = $this->poster()->post($this->approved($this->monthly($component, '2026-01')->commissions()->sole()));
        $this->assertSame('200', $this->walletBalance($this->members['ALICE']));
        $commissionRow = (array) DB::table('mlm_commissions')->where('id', $commission->id)->first();
        $touched = [];
        DB::listen(static function (QueryExecuted $query) use (&$touched): void {
            foreach (['mlm_commissions', 'mlm_commission_adjustments', 'mlm_commission_periods', 'mlm_calculation_runs', 'mlm_genealogy_paths'] as $table) {
                if (str_contains($query->sql, $table)) {
                    $touched[] = $table;
                }
            }
        });

        $request = $this->payouts()->approve($this->payoutRequest($this->members['ALICE'], '170'), now());
        $this->payouts()->settle($this->payouts()->startProcessing($request, now()), 'BANK-TRX-1', now());

        $this->assertSame([], $touched);
        $this->assertSame('30', $this->walletBalance($this->members['ALICE']));
        $this->assertEquals($commissionRow, (array) DB::table('mlm_commissions')->where('id', $commission->id)->first());
        DB::getEventDispatcher()?->forget(QueryExecuted::class);

        // A later clawback may take the wallet below zero; the payout stays as it was.
        $payoutRow = (array) DB::table('mlm_payout_requests')->where('id', $request->id)->first();
        $this->app->make(CommissionAdjustmentEngine::class)->processVolumeReversal($this->reverse($sale, 'refund:1', at: CarbonImmutable::parse('2026-03-10')));

        $this->assertSame([CommissionStatus::Reversed, '-70'], [Commission::query()->sole()->status, $this->walletBalance($this->members['ALICE'])]);
        $this->assertEquals($payoutRow, (array) DB::table('mlm_payout_requests')->where('id', $request->id)->first());
    }
}
