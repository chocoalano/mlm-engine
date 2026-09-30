<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Panel;

use ArrayObject;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use PandaBear\Mlm\Models\PayoutBatch;
use PandaBear\Mlm\Models\PayoutRequest;
use PandaBear\Mlm\Panel\Resources\Payouts\PayoutBatchResource;
use PandaBear\Mlm\Panel\Resources\Payouts\PayoutRequestResource;
use PandaBear\Mlm\Payout\PayoutBatchManager;
use PandaBear\Mlm\Payout\PayoutBatchStatus;
use PandaBear\Mlm\Payout\PayoutManager;
use PandaBear\Mlm\Payout\PayoutRequestStatus;
use PandaBear\Mlm\Tests\Panel\Concerns\BuildsOperations;

/**
 * Payout request and batch actions from the panel (ADR-031): each is one
 * call to `PayoutManager` or `PayoutBatchManager`, and what they refuse is
 * shown to the operator with nothing changed.
 */
final class PayoutPanelActionsTest extends PanelTestCase
{
    use BuildsOperations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operatingProgram('BOB');

        foreach ($this->team as $code => $member) {
            $this->fundedWallet($member, '100', "fund:{$code}");
            $this->settlementAccount($member);
        }
    }

    public function test_approve_start_and_settle_go_through_the_payout_manager_and_move_money_once(): void
    {
        $this->grantAll();
        $resolved = $this->spyOn(PayoutManager::class);
        $request = $this->payoutRequest($this->team['ALICE'], '30', 'payout:1');
        $resolved[PayoutManager::class] = 0;

        $this->act('payout', 'record', 'approve', $request)->assertSessionHas('success', 'Payout request approved; funds reserved.');

        $this->assertSame(PayoutRequestStatus::Approved, $request->refresh()->status);
        $this->assertSame('70', $this->walletBalance($this->team['ALICE']));

        $this->act('payout', 'infolist', 'start-processing', $request)->assertSessionHas('success');
        $this->assertSame(PayoutRequestStatus::Processing, $request->refresh()->status);

        $this->submit('payout', 'settle', $request, ['settlement_reference' => 'BANK-777', 'settled_at' => '2026-03-02 09:30:00'])
            ->assertSessionHas('success', 'Payout request settled.');

        $request->refresh();
        $this->assertSame([PayoutRequestStatus::Settled, 'BANK-777', '2026-03-02 09:30:00'], [$request->status, $request->settlement_reference, $request->settled_at?->format('Y-m-d H:i:s')]);

        // Settlement records the transfer; the money moved at approval.
        $this->assertSame('70', $this->walletBalance($this->team['ALICE']));
        $this->assertSame(3, $resolved[PayoutManager::class]);
    }

    public function test_failing_returns_the_reservation_and_cancelling_moves_nothing(): void
    {
        $this->grantAll();
        $failing = $this->payoutRequest($this->team['ALICE'], '40', 'payout:1');
        $cancelled = $this->payoutRequest($this->team['ALICE'], '5', 'payout:2');

        $this->act('payout', 'record', 'approve', $failing)->assertSessionHas('success');
        $this->assertSame('60', $this->walletBalance($this->team['ALICE']));

        $this->submit('payout', 'fail', $failing, ['failure_reason' => 'Account closed', 'failed_at' => '2026-03-03 10:00:00'])
            ->assertSessionHas('success', 'Payout request failed; funds returned to the wallet.');

        $failing->refresh();
        $this->assertSame([PayoutRequestStatus::Failed, 'Account closed'], [$failing->status, $failing->failure_reason]);
        $this->assertNotNull($failing->refund_ledger_transaction_id);
        $this->assertSame('100', $this->walletBalance($this->team['ALICE']));

        $this->submit('payout', 'cancel', $cancelled, ['reason' => 'Duplicate request'])->assertSessionHas('success');

        $this->assertSame(PayoutRequestStatus::Cancelled, $cancelled->refresh()->status);
        $this->assertSame('100', $this->walletBalance($this->team['ALICE']));
    }

    public function test_approving_beyond_the_balance_is_refused_with_the_reason_and_nothing_moves(): void
    {
        $this->grantAll();
        $request = $this->payoutRequest($this->team['ALICE'], '500', 'payout:big');
        $ledger = $this->ledgerRows();

        $this->act('payout', 'record', 'approve', $request)
            ->assertSessionMissing('success')
            ->assertSessionHas('error', fn (string $message): bool => str_starts_with($message, 'Approve was refused:'));

        $this->assertSame(PayoutRequestStatus::Requested, $request->refresh()->status);
        $this->assertSame('100', $this->walletBalance($this->team['ALICE']));
        $this->assertSame($ledger, $this->ledgerRows());
    }

    public function test_settling_a_request_that_is_not_processing_is_refused_by_the_manager(): void
    {
        $this->grantAll();
        $request = $this->payoutRequest($this->team['ALICE'], '10', 'payout:1');

        // Not offered — and a request that asks anyway is refused by the
        // manager, not by the panel.
        panelRecordActions(PayoutRequestResource::class)->assertHidden('settle', $request);

        $this->submit('payout', 'settle', $request, ['settlement_reference' => 'BANK-1', 'settled_at' => '2026-03-02 09:30:00'])
            ->assertSessionHas('error', fn (string $message): bool => str_starts_with($message, 'Settle was refused:'));

        $request->refresh();
        $this->assertSame([PayoutRequestStatus::Requested, null], [$request->status, $request->settlement_reference]);
    }

    public function test_a_view_only_operator_sees_no_financial_action_and_is_refused_every_one(): void
    {
        $this->grantViewOnly();
        $request = $this->payoutRequest($this->team['ALICE'], '10', 'payout:1');
        $batch = $this->payoutBatches()->create($this->program, 'IDR', 'batch:1');

        foreach (['approve', 'start-processing', 'settle', 'fail', 'cancel'] as $action) {
            panelRecordActions(PayoutRequestResource::class)->assertHidden($action, $request)->assertCanNotRun($action, $request);
            panelInfolistActions(PayoutRequestResource::class)->assertHidden($action, $request);
        }

        foreach (['seal', 'start-batch', 'complete', 'cancel-batch'] as $action) {
            panelRecordActions(PayoutBatchResource::class)->assertHidden($action, $batch)->assertCanNotRun($action, $batch);
        }

        $this->act('payout', 'record', 'approve', $request)->assertForbidden();
        $this->submit('payout', 'cancel', $request, ['reason' => 'x'])->assertForbidden();
        $this->act('batch', 'record', 'seal', $batch)->assertForbidden();

        $this->assertSame(PayoutRequestStatus::Requested, $request->refresh()->status);
        $this->assertSame(PayoutBatchStatus::Open, $batch->refresh()->status);
        $this->assertSame('100', $this->walletBalance($this->team['ALICE']));
    }

    public function test_a_batched_request_is_started_by_its_batch_and_the_request_action_is_refused(): void
    {
        $this->grantAll();
        $request = $this->approvedRequest('ALICE', '10', 'payout:1');
        $batch = $this->payoutBatches()->create($this->program, 'IDR', 'batch:1');
        $this->payoutBatches()->add($batch, $request);

        $this->act('payout', 'record', 'start-processing', $request)
            ->assertSessionHas('error', fn (string $message): bool => str_starts_with($message, 'Start processing was refused:'));

        $this->assertSame(PayoutRequestStatus::Approved, $request->refresh()->status);
    }

    public function test_seal_start_and_complete_go_through_the_batch_manager(): void
    {
        $this->grantAll();
        $resolved = $this->spyOn(PayoutBatchManager::class);
        $alice = $this->approvedRequest('ALICE', '10', 'payout:1');
        $bob = $this->approvedRequest('BOB', '20', 'payout:2');
        $batch = $this->payoutBatches()->create($this->program, 'IDR', 'batch:1');
        $this->payoutBatches()->add($batch, $alice);
        $this->payoutBatches()->add($batch, $bob);
        $resolved[PayoutBatchManager::class] = 0;

        $this->act('batch', 'record', 'seal', $batch)->assertSessionHas('success', 'Payout batch sealed.');
        $this->assertSame(PayoutBatchStatus::Sealed, $batch->refresh()->status);

        $this->act('batch', 'infolist', 'start-batch', $batch)->assertSessionHas('success');
        $this->assertSame(PayoutBatchStatus::Processing, $batch->refresh()->status);
        $this->assertSame([PayoutRequestStatus::Processing, PayoutRequestStatus::Processing], [$alice->refresh()->status, $bob->refresh()->status]);

        // Completing waits for every request to be settled or failed.
        $this->act('batch', 'record', 'complete', $batch)
            ->assertSessionHas('error', fn (string $message): bool => str_starts_with($message, 'Complete was refused:'));
        $this->assertSame(PayoutBatchStatus::Processing, $batch->refresh()->status);

        $this->submit('payout', 'settle', $alice, ['settlement_reference' => 'BANK-1', 'settled_at' => '2026-03-05 10:00:00'])->assertSessionHas('success');
        $this->submit('payout', 'fail', $bob, ['failure_reason' => 'Rejected', 'failed_at' => '2026-03-05 10:05:00'])->assertSessionHas('success');

        $this->act('batch', 'record', 'complete', $batch)->assertSessionHas('success', 'Payout batch completed.');
        $this->assertSame(PayoutBatchStatus::Completed, $batch->refresh()->status);
        $this->assertSame(4, $resolved[PayoutBatchManager::class]);

        $this->assertSame('100', $this->walletBalance($this->team['BOB']));
        $this->assertSame('90', $this->walletBalance($this->team['ALICE']));
    }

    public function test_cancelling_an_open_batch_leaves_its_requests_as_they_are(): void
    {
        $this->grantAll();
        $request = $this->approvedRequest('ALICE', '10', 'payout:1');
        $batch = $this->payoutBatches()->create($this->program, 'IDR', 'batch:1');
        $this->payoutBatches()->add($batch, $request);

        panelRecordActions(PayoutBatchResource::class)->assertVisible('cancel-batch', $batch)->assertVisible('seal', $batch)->assertHidden('complete', $batch);

        $this->act('batch', 'record', 'cancel-batch', $batch)->assertSessionHas('success', 'Payout batch cancelled.');

        $this->assertSame(PayoutBatchStatus::Cancelled, $batch->refresh()->status);
        $this->assertSame(PayoutRequestStatus::Approved, $request->refresh()->status);
        $this->assertSame('90', $this->walletBalance($this->team['ALICE']));
    }

    public function test_the_batch_detail_shows_exact_totals_and_its_ordered_requests(): void
    {
        $this->grantAll();
        $alice = $this->approvedRequest('ALICE', '10.25', 'payout:1');
        $bob = $this->approvedRequest('BOB', '20.5', 'payout:2');
        $batch = $this->payoutBatches()->create($this->program, 'IDR', 'batch:1');
        $this->payoutBatches()->add($batch, $bob);
        $this->payoutBatches()->add($batch, $alice);

        $page = $this->withHeaders(['X-Inertia' => 'true'])->get("/mlm/mlm-payout-batches/{$batch->id}")->assertOk();
        $entries = collect($page->json('props.infolist.schema'))->flatMap(static fn (array $section): array => $section['schema'] ?? [])->pluck('value', 'name');

        $this->assertSame(['2', '30.75 IDR', '30.75 IDR'], [$entries['items_count'], $entries['requested_total'], $entries['reserved_total']]);

        $items = collect($page->json('props.relations'))->firstWhere('key', 'items');
        $this->assertSame(['BOB', 'ALICE'], array_column(array_column($items['rows'], 'cells'), 'request.member.member_code'));
        $this->assertSame([], $items['headerActions']);
    }

    private function approvedRequest(string $member, string $amount, string $key): PayoutRequest
    {
        return $this->payouts()->approve($this->payoutRequest($this->team[$member], $amount, $key), CarbonImmutable::parse('2026-03-01 11:00:00'));
    }

    /**
     * @return ArrayObject<class-string, int>
     */
    private function spyOn(string $class): ArrayObject
    {
        $resolved = new ArrayObject([$class => 0]);

        $this->app->resolving($class, static function () use ($resolved, $class): void {
            $resolved[$class]++;
        });

        return $resolved;
    }

    private function act(string $kind, string $endpoint, string $action, PayoutRequest|PayoutBatch $record): TestResponse
    {
        return $this->post("/mlm/actions/{$endpoint}", [
            'resource' => $kind === 'batch' ? 'mlm-payout-batches' : 'mlm-payout-requests',
            'action' => $action,
            'record' => $record->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function submit(string $kind, string $action, PayoutRequest|PayoutBatch $record, array $data): TestResponse
    {
        return $this->post('/mlm/actions/form', [
            'resource' => $kind === 'batch' ? 'mlm-payout-batches' : 'mlm-payout-requests',
            'action' => $action,
            'scope' => 'record',
            'record' => $record->id,
            ...$data,
        ]);
    }
}
