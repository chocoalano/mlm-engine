<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Production;

use Carbon\CarbonImmutable;
use PandaBear\Mlm\Commission\CommissionAdjustmentEngine;
use PandaBear\Mlm\Commission\CommissionAdjustmentPoster;
use PandaBear\Mlm\Commission\CommissionPoster;
use PandaBear\Mlm\Commission\CommissionStatus;
use PandaBear\Mlm\Exceptions\ConflictingPayoutRequest;
use PandaBear\Mlm\Exceptions\ConflictingVolumeReplay;
use PandaBear\Mlm\Finance\FinancialAmount;
use PandaBear\Mlm\Genealogy\PlacementGenealogy;
use PandaBear\Mlm\Genealogy\SponsorGenealogy;
use PandaBear\Mlm\Matrix\MatrixNetworkManager;
use PandaBear\Mlm\Matrix\MatrixPlacementManager;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\LedgerPosting;
use PandaBear\Mlm\Models\LedgerTransaction;
use PandaBear\Mlm\Models\PayoutRequest;
use PandaBear\Mlm\Models\PlacementEdge;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Models\VolumeEntry;
use PandaBear\Mlm\Models\Wallet;
use PandaBear\Mlm\Payout\PayoutLedger;
use PandaBear\Mlm\Payout\PayoutRequestStatus;
use PandaBear\Mlm\Period\CommissionPeriodCalculator;
use PandaBear\Mlm\Period\CommissionPeriodFinalizer;
use PandaBear\Mlm\Period\CommissionPeriodManager;
use PandaBear\Mlm\Period\CommissionPeriodReleaser;
use PandaBear\Mlm\Program\ProgramManager;
use PandaBear\Mlm\Tests\DatabaseTestCase;
use PandaBear\Mlm\Tests\Panel\Concerns\BuildsOperations;
use PandaPanel\Core\PanelManager;

/**
 * The release scenarios (docs/production-readiness.md): the whole cycle on
 * the backend alone — no panel, no route, no signed-in user — its failure
 * and correction paths, and every retry along it, with the ledger balanced
 * and within its program and currency at every step.
 */
final class ReleaseScenarioTest extends DatabaseTestCase
{
    use BuildsOperations;

    public function test_the_whole_cycle_runs_on_the_backend_alone_and_keeps_every_audit_link(): void
    {
        $this->assertSame([], $this->app->make(PanelManager::class)->all(), 'A panel was needed.');
        $this->assertNull(auth()->user());

        $programs = $this->app->make(ProgramManager::class);
        $this->program = $programs->create('MAIN', 'Main Program');
        $alice = $programs->join($this->program, 'ALICE', CarbonImmutable::parse('2025-12-01'), 'user', '1');
        $bob = $programs->join($this->program, 'BOB', CarbonImmutable::parse('2025-12-01'), 'user', '2');
        $this->team = ['ALICE' => $alice, 'BOB' => $bob];

        // Networks: sponsor, placement and the matrix overlay on it.
        $this->travelTo(CarbonImmutable::parse('2026-01-01 00:00:00'));
        $this->app->make(SponsorGenealogy::class)->assignSponsor($bob, $alice);
        $this->app->make(PlacementGenealogy::class)->place($bob, $alice);
        $this->app->make(MatrixNetworkManager::class)->configure($this->program, 2);
        $this->app->make(MatrixPlacementManager::class)->adopt(PlacementEdge::query()->where('member_id', $bob->id)->sole(), 1);
        $this->travelBack();

        $this->source = $this->systemAccounts()->openSystemAccount($this->program, 'IDR', 'commission.payable');
        $settlement = $this->systemAccounts()->openSystemAccount($this->program, 'IDR', 'payout.settlement');
        $plan = $programs->addPlan($this->program, 'COMP', 'Compensation');
        $version = $this->activeVersionOf($plan);

        // A sale in January, its period calculated, reviewed, held, released
        // and posted — money reaches the wallet only at posting.
        $sale = $this->sale($bob, '150', '2026-01-10', 'order:1');
        $period = $this->app->make(CommissionPeriodManager::class)->create($this->program, $version, $this->source, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-02-01'), CarbonImmutable::parse('2026-02-15'), 'period:2026-01');
        $this->app->make(CommissionPeriodCalculator::class)->calculate($period);
        $commission = $this->approved(Commission::query()->sole());
        $this->app->make(CommissionPeriodFinalizer::class)->finalize($period);
        $this->assertSame([], $this->transactionsOf('commission'), 'Finalizing moved money.');
        $this->app->make(CommissionPeriodReleaser::class)->release($period, CarbonImmutable::parse('2026-02-15'));
        $posted = $this->app->make(CommissionPoster::class)->post($commission->refresh());

        $this->assertSame(CommissionStatus::Posted, $posted->status);
        $this->assertSame('10', $this->walletBalance($alice));

        // Paid out through a reservation at approval; settlement moves nothing.
        $request = $this->payouts()->request($alice, Wallet::query()->where('member_id', $alice->id)->sole(), $settlement, '10', 'bank-account', 'ref-alice', CarbonImmutable::parse('2026-03-01 10:00:00'), 'payout:1');
        $this->payouts()->approve($request, CarbonImmutable::parse('2026-03-01 11:00:00'));
        $this->payouts()->startProcessing($request, CarbonImmutable::parse('2026-03-01 12:00:00'));
        $settled = $this->payouts()->settle($request, 'BANK-1', CarbonImmutable::parse('2026-03-02 09:00:00'));

        $this->assertSame([PayoutRequestStatus::Settled, 'BANK-1'], [$settled->status, $settled->settlement_reference]);
        $this->assertSame('0', $this->walletBalance($alice));

        // Every audit link, end to end.
        $this->assertTrue($posted->run->periodRun->period->is($period));
        $this->assertTrue($posted->run->component->planVersion->is($version));
        $this->assertSame(['volume-entry', $sale->id], [$posted->source_type, $posted->source_id]);
        $this->assertSame([CommissionPoster::SOURCE_TYPE, $posted->id], [$posted->ledgerTransaction->source_type, $posted->ledgerTransaction->source_id]);
        $this->assertSame([PayoutLedger::SOURCE_TYPE, $settled->id], [$settled->reservation->source_type, $settled->reservation->source_id]);
        $this->assertNull($settled->refund_ledger_transaction_id);
        $this->assertSame(2, LedgerTransaction::query()->count());
        $this->assertLedgerIntegrity();
    }

    public function test_a_failed_payout_returns_its_reservation_exactly(): void
    {
        $this->operatingProgram();
        $this->fundedWallet($this->team['ALICE'], '100', 'fund:ALICE');
        $this->settlementAccount($this->team['ALICE']);

        $request = $this->payoutRequest($this->team['ALICE'], '40', 'payout:1');
        $this->assertSame(['100', null], [$this->walletBalance($this->team['ALICE']), $request->reservation_ledger_transaction_id], 'Requesting moved money.');

        $this->payouts()->approve($request, CarbonImmutable::parse('2026-03-01 11:00:00'));
        $this->assertSame('60', $this->walletBalance($this->team['ALICE']));
        $this->payouts()->startProcessing($request, CarbonImmutable::parse('2026-03-01 12:00:00'));
        $failed = $this->payouts()->fail($request, 'Account closed', CarbonImmutable::parse('2026-03-02 09:00:00'));

        $this->assertSame([PayoutRequestStatus::Failed, 'Account closed'], [$failed->status, $failed->failure_reason]);
        $this->assertSame('100', $this->walletBalance($this->team['ALICE']));
        $this->assertSame($failed->reservation_ledger_transaction_id, $failed->refund->reversal_of_id);
        $this->assertSame('2026-03-02 09:00:00', $failed->refund->occurred_at->format('Y-m-d H:i:s'));
        $this->assertLedgerIntegrity();
    }

    /**
     * Payout history is never rewritten: a commission clawed back after its
     * funds were paid out debits the wallet, which may go negative.
     */
    public function test_a_settled_payout_stays_settled_when_its_commission_is_later_clawed_back(): void
    {
        $this->operatingProgram('BOB');
        $sale = $this->sale($this->team['BOB'], '150', '2026-01-10', 'order:1');
        $component = $this->fixedComponent('direct-sponsor.fixed', $this->directParameters(), Plan::factory()->for($this->program)->create());
        $posted = $this->app->make(CommissionPoster::class)->post($this->approved(Commission::query()->where('calculation_run_id', $this->monthly($component, '2026-01')->id)->sole()));
        $this->settlementAccount($this->team['ALICE']);

        $request = $this->payoutRequest($this->team['ALICE'], '10', 'payout:1');
        $this->payouts()->approve($request, CarbonImmutable::parse('2026-03-01 11:00:00'));
        $this->payouts()->startProcessing($request, CarbonImmutable::parse('2026-03-01 12:00:00'));
        $settled = $this->payouts()->settle($request, 'BANK-1', CarbonImmutable::parse('2026-03-02 09:00:00'));
        $payoutRow = $settled->refresh()->getAttributes();

        $reversal = $this->reverse($sale, 'refund:1', at: CarbonImmutable::parse('2026-04-10 08:00:00'));
        $this->app->make(CommissionAdjustmentEngine::class)->processVolumeReversal($reversal);

        $this->assertSame(CommissionStatus::Reversed, $posted->refresh()->status);
        $this->assertSame('-10', $this->walletBalance($this->team['ALICE']));
        $this->assertSame($payoutRow, PayoutRequest::query()->findOrFail($settled->id)->getAttributes(), 'The settled payout was rewritten.');
        $this->assertLedgerIntegrity();
    }

    public function test_every_step_of_the_chain_replays_without_moving_money_twice(): void
    {
        $this->operatingProgram('BOB');
        $version = $this->activeVersion();
        $sale = $this->sale($this->team['BOB'], '150', '2026-01-10', 'order:1');

        // The same entry, recorded again, is the same entry; other facts
        // under its key are refused.
        $this->assertTrue($sale->is($this->sale($this->team['BOB'], '150', '2026-01-10', 'order:1')));
        $this->assertThrows(fn () => $this->sale($this->team['BOB'], '151', '2026-01-10', 'order:1'), ConflictingVolumeReplay::class);

        $period = $this->openPeriod($version);
        $first = $this->app->make(CommissionPeriodCalculator::class)->calculate($period);
        $again = $this->app->make(CommissionPeriodCalculator::class)->calculate($period);
        $this->assertSame(array_map(static fn ($run) => $run->id, $first->calculationRuns()), array_map(static fn ($run) => $run->id, $again->calculationRuns()));
        $this->assertSame(1, Commission::query()->count());

        $commission = $this->approved(Commission::query()->sole());
        $this->app->make(CommissionPeriodFinalizer::class)->finalize($period);
        $this->app->make(CommissionPeriodFinalizer::class)->finalize($period);
        $this->app->make(CommissionPeriodReleaser::class)->release($period, CarbonImmutable::parse('2026-02-15'));
        $this->app->make(CommissionPeriodReleaser::class)->release($period, CarbonImmutable::parse('2026-03-01'));

        $posted = $this->app->make(CommissionPoster::class)->post($commission->refresh());
        $this->assertSame($posted->ledger_transaction_id, $this->app->make(CommissionPoster::class)->post($posted)->ledger_transaction_id);

        $this->settlementAccount($this->team['ALICE']);
        $request = $this->payoutRequest($this->team['ALICE'], '10', 'payout:1');
        $this->assertTrue($request->is($this->payoutRequest($this->team['ALICE'], '10', 'payout:1')));
        $this->assertThrows(fn () => $this->payoutRequest($this->team['ALICE'], '9', 'payout:1'), ConflictingPayoutRequest::class);

        $approved = $this->payouts()->approve($request, CarbonImmutable::parse('2026-03-01 11:00:00'));
        $this->assertSame($approved->reservation_ledger_transaction_id, $this->payouts()->approve($request, CarbonImmutable::parse('2026-03-01 11:00:00'))->reservation_ledger_transaction_id);
        $this->payouts()->startProcessing($request, CarbonImmutable::parse('2026-03-01 12:00:00'));
        $this->payouts()->startProcessing($request, CarbonImmutable::parse('2026-03-01 12:00:00'));
        $this->payouts()->settle($request, 'BANK-1', CarbonImmutable::parse('2026-03-02 09:00:00'));
        $this->payouts()->settle($request, 'BANK-1', CarbonImmutable::parse('2026-03-02 09:00:00'));
        $this->assertThrows(fn () => $this->payouts()->settle($request, 'BANK-2', CarbonImmutable::parse('2026-03-02 09:00:00')), ConflictingPayoutRequest::class);

        // Two movements happened — the posting and the reservation — however
        // often each step was asked for.
        $this->assertSame(1, VolumeEntry::query()->count());
        $this->assertSame([1, 1], [count($this->transactionsOf(CommissionPoster::SOURCE_TYPE)), count($this->transactionsOf(PayoutLedger::SOURCE_TYPE))]);
        $this->assertSame('0', $this->walletBalance($this->team['ALICE']));
        $this->assertLedgerIntegrity();
    }

    /**
     * An active version of a new draft of the plan, paying a fixed direct
     * sponsor commission from the program's source account.
     */
    private function activeVersionOf(Plan $plan): PlanVersion
    {
        $draft = $this->lifecycle()->draft($plan);
        $this->editor()->addComponent($draft, 'direct', 'commission.strategy', 'Direct', $this->commissionParameters([
            'strategy' => 'direct-sponsor.fixed',
            'parameters' => ['volume_type' => 'sales', 'source_type' => 'order', 'minimum_quantity' => '1', 'amount' => '10'],
        ]));
        $this->lifecycle()->markValidated($draft);
        $this->lifecycle()->publish($draft->refresh());

        return $this->lifecycle()->activate($draft->refresh());
    }

    /**
     * @return list<LedgerTransaction>
     */
    private function transactionsOf(string $sourceType): array
    {
        return LedgerTransaction::query()->where('source_type', $sourceType)->get()->all();
    }

    /**
     * Every transaction the package wrote balances exactly, stays within one
     * program and one currency, names a source of a kind the package posts,
     * and a reversal is its original's exact negation.
     */
    private function assertLedgerIntegrity(): void
    {
        $transactions = LedgerTransaction::query()->with('postings.account')->get();
        $this->assertNotEmpty($transactions);

        foreach ($transactions as $transaction) {
            $postings = $transaction->postings;

            $this->assertGreaterThanOrEqual(2, $postings->count(), "Transaction [{$transaction->id}] has one side.");
            $this->assertTrue(FinancialAmount::sum($postings->map(static fn (LedgerPosting $posting): FinancialAmount => $posting->amount))->isZero(), "Transaction [{$transaction->id}] does not balance.");
            $this->assertContains($transaction->source_type, ['commission', CommissionAdjustmentPoster::SOURCE_TYPE, PayoutLedger::SOURCE_TYPE, 'manual', 'refund'], "Transaction [{$transaction->id}] names an unknown source.");
            $this->assertNotSame('', (string) $transaction->idempotency_key);

            foreach ($postings as $posting) {
                $this->assertSame([$transaction->program_id, $transaction->currency], [$posting->account->program_id, $posting->account->currency], "Transaction [{$transaction->id}] crosses a program or currency.");
            }

            if ($transaction->reversal_of_id !== null) {
                $original = LedgerTransaction::query()->with('postings')->findOrFail($transaction->reversal_of_id);
                $this->assertEquals(
                    $original->postings->mapWithKeys(static fn (LedgerPosting $posting): array => [$posting->ledger_account_id => $posting->amount->negate()->value()])->sortKeys()->all(),
                    $postings->mapWithKeys(static fn (LedgerPosting $posting): array => [$posting->ledger_account_id => $posting->amount->value()])->sortKeys()->all(),
                    "Reversal [{$transaction->id}] is not its original's negation.",
                );
            }
        }

        // Every wallet's balance is exactly its postings' sum.
        foreach (Wallet::query()->get() as $wallet) {
            $account = LedgerAccount::query()->where('wallet_id', $wallet->id)->sole();
            $this->assertTrue(FinancialAmount::sum(LedgerPosting::query()->where('ledger_account_id', $account->id)->get()->map(static fn (LedgerPosting $posting): FinancialAmount => $posting->amount))->equals($this->balances()->forWallet($wallet)));
        }

    }
}
