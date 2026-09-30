<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Panel;

use Carbon\CarbonImmutable;
use PandaBear\Mlm\Commission\CommissionStatus;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\CommissionPeriod;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\PayoutBatch;
use PandaBear\Mlm\Models\PayoutBatchItem;
use PandaBear\Mlm\Models\PayoutRequest;
use PandaBear\Mlm\Models\Wallet;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Resources\Payouts\PayoutBatchItemsRelation;
use PandaBear\Mlm\Panel\Resources\Payouts\PayoutBatchResource;
use PandaBear\Mlm\Panel\Resources\Payouts\PayoutRequestResource;
use PandaBear\Mlm\Panel\Support\Options;
use PandaBear\Mlm\Payout\PayoutBatchManager;
use PandaBear\Mlm\Payout\PayoutManager;
use PandaBear\Mlm\Payout\PayoutRequestStatus;
use PandaBear\Mlm\Period\CommissionPeriodCalculator;
use PandaBear\Mlm\Period\CommissionPeriodFinalizer;
use PandaBear\Mlm\Period\CommissionPeriodManager;
use PandaBear\Mlm\Period\CommissionPeriodReleaser;
use PandaBear\Mlm\Period\CommissionPeriodStatus;
use PandaBear\Mlm\Tests\Panel\Concerns\BuildsOperations;
use PandaPanel\Infolists\InfolistSchema;
use PandaPanel\Resources\RelationTable;

/**
 * The operations that complete the cycle: opening a period, reviewing and
 * posting its commissions, requesting payouts and composing batches — each
 * through its domain service, with nothing moved that the step does not
 * move.
 */
final class OperationsPanelTest extends PanelTestCase
{
    use BuildsOperations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operatingProgram('BOB');

        foreach ($this->team as $code => $member) {
            $this->fundedWallet($member, '100', "fund:{$code}");
        }

        $this->settlementAccount($this->team['ALICE']);
    }

    public function test_a_payout_request_is_recorded_through_the_payout_manager_and_moves_nothing(): void
    {
        $this->grant(MlmPermission::PAYOUTS_VIEW, MlmPermission::PAYOUTS_OPERATE);
        $resolved = $this->spyOn([PayoutManager::class]);
        $wallet = Wallet::query()->where('member_id', $this->team['ALICE']->id)->sole();
        $settlement = LedgerAccount::query()->where('key', 'payout.settlement')->sole();
        $ledger = $this->ledgerRows();

        $this->submitTable('mlm-payout-requests', 'new-payout-request', $this->requestData($wallet, $settlement, ['amount' => '250']))
            ->assertSessionHas('success', 'Payout request recorded.');

        $request = PayoutRequest::query()->sole();
        $this->assertSame([PayoutRequestStatus::Requested, 'IDR', $this->program->id, '250'], [$request->status, $request->currency, $request->program_id, $request->amount->value()]);
        $this->assertSame($ledger, $this->ledgerRows(), 'Requesting moved money.');
        $this->assertSame('100', $this->walletBalance($this->team['ALICE']));
        $this->assertSame(1, $resolved[PayoutManager::class]);

        // The same key with other facts, and another member's wallet, are the
        // manager's refusals.
        $this->submitTable('mlm-payout-requests', 'new-payout-request', $this->requestData($wallet, $settlement, ['amount' => '5']))
            ->assertSessionHas('error', fn (string $message): bool => str_starts_with($message, 'New payout request was refused:'));
        $bobs = Wallet::query()->where('member_id', $this->team['BOB']->id)->sole();
        $this->submitTable('mlm-payout-requests', 'new-payout-request', $this->requestData($bobs, $settlement, ['idempotency_key' => 'payout:2']))
            ->assertSessionHas('error');

        $this->assertSame(1, PayoutRequest::query()->count());
    }

    public function test_the_form_offers_the_members_wallets_with_balances_and_only_system_accounts_of_that_program_and_currency(): void
    {
        $wallet = Wallet::query()->where('member_id', $this->team['ALICE']->id)->sole();
        $this->systemAccounts()->openSystemAccount($this->program, 'USD', 'payout.settlement');

        $this->assertSame([$wallet->id => 'IDR · balance 100 IDR'], Options::walletsOf($this->team['ALICE']->id));

        $settlements = Options::settlementAccountsFor($wallet->id);
        $this->assertSame(['payout.settlement · IDR'], array_values(array_filter($settlements, static fn (string $label): bool => str_starts_with($label, 'payout.'))));

        foreach (array_keys($settlements) as $id) {
            $account = LedgerAccount::query()->findOrFail($id);
            $this->assertSame([null, 'IDR', $this->program->id], [$account->wallet_id, $account->currency, $account->program_id]);
        }
    }

    public function test_batches_are_created_and_composed_through_the_batch_manager_and_sealing_ends_membership(): void
    {
        $this->grant(MlmPermission::PAYOUTS_VIEW, MlmPermission::PAYOUTS_OPERATE);
        $resolved = $this->spyOn([PayoutBatchManager::class]);
        $approved = $this->payouts()->approve($this->payoutRequest($this->team['ALICE'], '10', 'payout:1'), CarbonImmutable::parse('2026-03-01 11:00:00'));
        $requested = $this->payoutRequest($this->team['ALICE'], '5', 'payout:2');

        $this->submitTable('mlm-payout-batches', 'new-payout-batch', ['program_id' => $this->program->id, 'currency' => 'IDR', 'idempotency_key' => 'batch:1'])
            ->assertSessionHas('success', 'Payout batch created.');
        $batch = PayoutBatch::query()->sole();

        $this->assertSame([$approved->id], array_keys(Options::batchCandidates($batch)), 'Only approved, unbatched requests of the batch program and currency are offered.');

        $this->submitRecord('mlm-payout-batches', 'add-request', $batch, ['payout_request_id' => $approved->id])->assertSessionHas('success', 'Request added to the batch.');
        $this->submitRecord('mlm-payout-batches', 'add-request', $batch, ['payout_request_id' => $approved->id])->assertSessionHas('success');
        $this->assertSame(1, PayoutBatchItem::query()->count(), 'Adding again replays, never duplicates.');
        $this->assertSame([], Options::batchCandidates($batch));

        $this->submitRecord('mlm-payout-batches', 'add-request', $batch, ['payout_request_id' => $requested->id])
            ->assertSessionHas('error', fn (string $message): bool => str_starts_with($message, 'Add request was refused:'));

        $other = $this->payoutBatches()->create($this->program, 'IDR', 'batch:2');
        $this->submitRecord('mlm-payout-batches', 'add-request', $other, ['payout_request_id' => $approved->id])->assertSessionHas('error');

        // Nothing removes a request from a batch, and a sealed batch takes
        // no more.
        $this->assertSame([], array_values(array_filter(
            array_keys(PayoutBatchResource::infolist(InfolistSchema::make())->allActions()),
            static fn (string $name): bool => preg_match('/remove|detach|delete/', $name) === 1,
        )));
        $this->assertSame([], RelationTable::forManager(PayoutBatchResource::class, PayoutBatchItemsRelation::class, $batch, request())['headerActions']);

        $this->runRecord('mlm-payout-batches', 'seal', $batch)->assertSessionHas('success');
        panelInfolistActions(PayoutBatchResource::class)->assertHidden('add-request', $batch->refresh());
        $late = $this->payouts()->approve($this->payoutRequest($this->team['BOB'], '1', 'payout:3', $this->settlementAccount($this->team['BOB'])), CarbonImmutable::parse('2026-03-01 11:00:00'));
        $this->submitRecord('mlm-payout-batches', 'add-request', $batch, ['payout_request_id' => $late->id])->assertSessionHas('error');

        $this->assertSame(1, $batch->items()->count());
        $this->assertGreaterThan(3, $resolved[PayoutBatchManager::class]);
    }

    public function test_viewing_payouts_is_not_requesting_or_batching_them(): void
    {
        $this->grant(MlmPermission::PAYOUTS_VIEW, MlmPermission::PERIODS_OPERATE, MlmPermission::NETWORK_OPERATE, MlmPermission::PLANS_OPERATE);
        $wallet = Wallet::query()->where('member_id', $this->team['ALICE']->id)->sole();

        panelTableActions(PayoutRequestResource::class)->assertHidden('new-payout-request');
        $this->submitTable('mlm-payout-requests', 'new-payout-request', $this->requestData($wallet, LedgerAccount::query()->where('key', 'payout.settlement')->sole()))->assertForbidden();
        $this->submitTable('mlm-payout-batches', 'new-payout-batch', ['program_id' => $this->program->id, 'currency' => 'IDR', 'idempotency_key' => 'batch:1'])->assertForbidden();

        $this->assertSame([0, 0], [PayoutRequest::query()->count(), PayoutBatch::query()->count()]);
    }

    public function test_a_period_is_opened_its_commissions_reviewed_and_posted_through_their_services(): void
    {
        $this->grant(MlmPermission::PERIODS_VIEW, MlmPermission::PERIODS_OPERATE, MlmPermission::COMMISSIONS_VIEW, MlmPermission::COMMISSIONS_OPERATE);
        $resolved = $this->spyOn([CommissionPeriodManager::class]);
        $version = $this->activeVersion();
        $this->sale($this->team['BOB'], '150', '2026-01-10', 'order:1');

        $this->submitTable('mlm-commission-periods', 'open-period', [
            'program_id' => $this->program->id, 'plan_version_id' => $version->id, 'source_ledger_account_id' => $this->source->id,
            'from_at' => '2026-01-01 00:00:00', 'until_at' => '2026-02-01 00:00:00', 'release_at' => '2026-02-15 00:00:00', 'idempotency_key' => 'period:2026-01',
        ])->assertSessionHas('success', 'Commission period opened.');

        $period = CommissionPeriod::query()->sole();
        $this->assertSame([CommissionPeriodStatus::Open, $version->id, 1], [$period->status, $period->plan_version_id, $resolved[CommissionPeriodManager::class]]);

        $this->submitTable('mlm-commission-periods', 'open-period', [
            'program_id' => $this->program->id, 'plan_version_id' => $version->id, 'source_ledger_account_id' => $this->source->id,
            'from_at' => '2026-01-15 00:00:00', 'until_at' => '2026-02-15 00:00:00', 'release_at' => '2026-02-20 00:00:00', 'idempotency_key' => 'period:overlap',
        ])->assertSessionHas('error', fn (string $message): bool => str_starts_with($message, 'Open period was refused:'));

        $this->app->make(CommissionPeriodCalculator::class)->calculate($period);
        $commission = Commission::query()->sole();

        $this->runRecord('mlm-commissions', 'approve-commission', $commission)->assertSessionHas('error', fn (string $message): bool => str_starts_with($message, 'Approve was refused:'));
        $this->runRecord('mlm-commissions', 'mark-pending', $commission)->assertSessionHas('success', 'Commission marked pending.');
        $this->runRecord('mlm-commissions', 'approve-commission', $commission, 'record')->assertSessionHas('success', 'Commission approved.');
        $this->assertSame(CommissionStatus::Approved, $commission->refresh()->status);

        $this->app->make(CommissionPeriodFinalizer::class)->finalize($period);
        $ledger = $this->ledgerRows();

        // Posting before the period is released is the poster's refusal.
        $this->runRecord('mlm-commissions', 'post-commission', $commission)->assertSessionHas('error');
        $this->assertSame($ledger, $this->ledgerRows());

        $this->app->make(CommissionPeriodReleaser::class)->release($period, CarbonImmutable::parse('2026-02-15 00:00:00'));
        $this->runRecord('mlm-commissions', 'post-commission', $commission)->assertSessionHas('success', 'Commission posted.');

        $this->assertSame(CommissionStatus::Posted, $commission->refresh()->status);
        $this->assertSame('110', $this->walletBalance($this->team['ALICE']));
    }

    public function test_viewing_commissions_is_not_reviewing_them(): void
    {
        $this->grant(MlmPermission::COMMISSIONS_VIEW, MlmPermission::PERIODS_OPERATE, MlmPermission::PAYOUTS_OPERATE);
        $this->sale($this->team['BOB'], '150', '2026-01-10', 'order:1');
        $this->app->make(CommissionPeriodCalculator::class)->calculate($this->openPeriod($this->activeVersion()));
        $commission = Commission::query()->sole();

        $this->runRecord('mlm-commissions', 'mark-pending', $commission)->assertForbidden();
        $this->assertSame(CommissionStatus::Calculated, $commission->refresh()->status);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function requestData(Wallet $wallet, LedgerAccount $settlement, array $overrides = []): array
    {
        return [
            'member_id' => $this->team['ALICE']->id,
            'wallet_id' => $wallet->id,
            'settlement_ledger_account_id' => $settlement->id,
            'amount' => '250',
            'destination_type' => 'bank-account',
            'destination_reference' => 'opaque-ref-001',
            'requested_at' => '2026-03-01 10:00:00',
            'idempotency_key' => 'payout:1',
            ...$overrides,
        ];
    }
}
