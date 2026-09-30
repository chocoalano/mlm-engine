<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Exceptions\ConflictingPayoutBatch;
use PandaBear\Mlm\Exceptions\CorruptPayoutBatch;
use PandaBear\Mlm\Exceptions\ImmutableCalculationRecord;
use PandaBear\Mlm\Exceptions\InvalidPayoutBatch;
use PandaBear\Mlm\Exceptions\InvalidPayoutTransition;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\PayoutBatch;
use PandaBear\Mlm\Models\PayoutBatchItem;
use PandaBear\Mlm\Models\PayoutRequest;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Payout\PayoutBatchStatus;
use PandaBear\Mlm\Payout\PayoutBatchTotals;
use PandaBear\Mlm\Payout\PayoutRequestStatus;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;
use PandaBear\Mlm\Tests\Concerns\BuildsLedgers;
use PandaBear\Mlm\Tests\Concerns\BuildsPayouts;

/**
 * Payout batches (ADR-030) group one program's approved requests in one
 * currency — open, sealed, processing, completed — and never move money:
 * each request keeps its reservation and is settled or failed on its own.
 */
final class PayoutBatchTest extends DatabaseTestCase
{
    use BuildsGenealogies;
    use BuildsLedgers;
    use BuildsPayouts;

    private Program $program;

    /**
     * @var array<string, Member>
     */
    private array $members;

    protected function setUp(): void
    {
        parent::setUp();

        $this->program = Program::factory()->create();
        $this->members = $this->members($this->program, 'ALICE', 'BOB', 'CAROL');

        foreach ($this->members as $code => $member) {
            $this->fundedWallet($member, '100', "fund:{$code}");
            $this->settlementAccount($member);
        }
    }

    public function test_a_batch_is_created_once_under_its_key(): void
    {
        $batch = $this->payoutBatches()->create($this->program, 'IDR', 'batch:1');

        $this->assertSame([PayoutBatchStatus::Open, 'IDR', $this->program->id], [$batch->status, $batch->currency, $batch->program_id]);
        $this->assertTrue($batch->is($this->payoutBatches()->create($this->program, 'IDR', 'batch:1')));

        $this->expectException(ConflictingPayoutBatch::class);
        $this->payoutBatches()->create($this->program, 'USD', 'batch:1');
    }

    public function test_approved_requests_join_an_open_batch_once_in_order(): void
    {
        $batch = $this->payoutBatches()->create($this->program, 'IDR', 'batch:1');
        $alice = $this->approved('ALICE', '10');
        $bob = $this->approved('BOB', '20');

        $first = $this->payoutBatches()->add($batch, $alice);
        $second = $this->payoutBatches()->add($batch, $bob);

        $this->assertSame([1, 2], [$first->position, $second->position]);
        $this->assertTrue($first->is($this->payoutBatches()->add($batch, $alice)));
        $this->assertSame([$alice->id, $bob->id], $batch->items()->pluck('payout_request_id')->all());
        $this->assertTrue($alice->batchItem?->batch->is($batch));

        $other = $this->payoutBatches()->create($this->program, 'IDR', 'batch:2');
        $foreign = Member::factory()->create();
        $this->fundedWallet($foreign, '10', 'fund:foreign');
        $this->settlementAccount($foreign);

        foreach ([
            'already belongs to payout batch' => fn () => $this->payoutBatches()->add($other, $alice),
            'only an approved request joins a batch' => fn () => $this->payoutBatches()->add($batch, $this->payoutRequest($this->members['CAROL'], '5', 'payout:carol')),
            'belongs to another program' => fn () => $this->payoutBatches()->add($batch, $this->payouts()->approve($this->payoutRequest($foreign, '5', 'payout:foreign'), now())),
            'is in IDR, the batch in USD' => fn () => $this->payoutBatches()->add($this->payoutBatches()->create($this->program, 'USD', 'batch:usd'), $this->approved('CAROL', '5', 'payout:carol-2')),
        ] as $reason => $add) {
            try {
                $add();
                $this->fail("A request joined a batch it cannot: {$reason}.");
            } catch (InvalidPayoutBatch $exception) {
                $this->assertStringContainsString($reason, $exception->getMessage());
            }
        }

        $this->assertSame(2, PayoutBatchItem::query()->count());
    }

    public function test_a_batch_is_sealed_with_requests_and_then_takes_no_more(): void
    {
        $batch = $this->payoutBatches()->create($this->program, 'IDR', 'batch:1');

        try {
            $this->payoutBatches()->seal($batch, now());
            $this->fail('An empty batch was sealed.');
        } catch (InvalidPayoutBatch $exception) {
            $this->assertStringContainsString('an empty batch is not sealed', $exception->getMessage());
        }

        $this->payoutBatches()->add($batch, $this->approved('ALICE', '10'));
        $sealed = $this->payoutBatches()->seal($batch, CarbonImmutable::parse('2026-03-05 09:00:00'));

        $this->assertSame([PayoutBatchStatus::Sealed, '2026-03-05 09:00:00'], [$sealed->status, $sealed->sealed_at?->format('Y-m-d H:i:s')]);

        $this->expectException(InvalidPayoutBatch::class);
        $this->expectExceptionMessage('requests join an open batch only');
        $this->payoutBatches()->add($batch, $this->approved('BOB', '10'));
    }

    public function test_a_batched_request_starts_with_its_batch_all_or_none(): void
    {
        $batch = $this->payoutBatches()->create($this->program, 'IDR', 'batch:1');
        $alice = $this->approved('ALICE', '10');
        $bob = $this->approved('BOB', '20');
        $this->payoutBatches()->add($batch, $alice);
        $this->payoutBatches()->add($batch, $bob);
        $this->payoutBatches()->seal($batch, now());

        try {
            $this->payouts()->startProcessing($alice, now());
            $this->fail('A batched request started on its own.');
        } catch (InvalidPayoutTransition $exception) {
            $this->assertStringContainsString('it starts processing with its batch', $exception->getMessage());
        }

        // One request failed meanwhile: nothing starts.
        $this->payouts()->fail($bob, 'provider-rejected', now());

        try {
            $this->payoutBatches()->startProcessing($batch, now());
            $this->fail('A batch started with a failed request.');
        } catch (InvalidPayoutBatch $exception) {
            $this->assertStringContainsString('every request must still be approved', $exception->getMessage());
        }

        $this->assertSame([PayoutRequestStatus::Approved, PayoutBatchStatus::Sealed], [$alice->refresh()->status, $batch->refresh()->status]);
    }

    public function test_a_batch_is_processed_settled_or_failed_request_by_request_and_completed_without_moving_money(): void
    {
        $batch = $this->payoutBatches()->create($this->program, 'IDR', 'batch:1');
        $requests = [$this->approved('ALICE', '10'), $this->approved('BOB', '20'), $this->approved('CAROL', '30')];

        foreach ($requests as $request) {
            $this->payoutBatches()->add($batch, $request);
        }

        $ledger = $this->ledgerRows();
        $this->payoutBatches()->seal($batch, now());
        $started = $this->payoutBatches()->startProcessing($batch, CarbonImmutable::parse('2026-03-06 08:00:00'));

        $this->assertSame([PayoutBatchStatus::Processing, '2026-03-06 08:00:00'], [$started->status, $started->processing_at?->format('Y-m-d H:i:s')]);
        $this->assertSame(['processing'], PayoutRequest::query()->pluck('status')->map(static fn (PayoutRequestStatus $status): string => $status->value)->unique()->values()->all());
        $this->assertSame($ledger, $this->ledgerRows());
        $this->assertTrue($started->is($this->payoutBatches()->startProcessing($batch, now())));

        $this->payouts()->settle($requests[0], 'BANK-1', now());
        $this->payouts()->fail($requests[1], 'account-closed', now());

        try {
            $this->payoutBatches()->complete($batch, now());
            $this->fail('A batch completed with a request still processing.');
        } catch (InvalidPayoutBatch $exception) {
            $this->assertStringContainsString('are still processing', $exception->getMessage());
        }

        $this->payouts()->settle($requests[2], 'BANK-3', now());
        $completed = $this->payoutBatches()->complete($batch, CarbonImmutable::parse('2026-03-07 18:00:00'));
        $again = $this->payoutBatches()->complete($batch, CarbonImmutable::parse('2026-04-01'));

        $this->assertSame([PayoutBatchStatus::Completed, '2026-03-07 18:00:00', '2026-03-07 18:00:00'], [$completed->status, $completed->completed_at?->format('Y-m-d H:i:s'), $again->completed_at?->format('Y-m-d H:i:s')]);
        // The failed request's reservation came back; the batch itself moved nothing.
        $this->assertSame(['90', '100', '70'], [$this->walletBalance($this->members['ALICE']), $this->walletBalance($this->members['BOB']), $this->walletBalance($this->members['CAROL'])]);
        $this->assertSame(0, DB::table('mlm_ledger_transactions')->where('source_type', 'payout-batch')->count());

        $totals = PayoutBatchTotals::of($batch);
        $this->assertSame([3, '60', '40', 2, '40', 1, '20'], [$totals->count, $totals->requested->value(), $totals->reserved->value(), $totals->settledCount, $totals->settled->value(), $totals->failedCount, $totals->failed->value()]);
    }

    public function test_an_all_failed_batch_completes_and_its_totals_are_exact(): void
    {
        $batch = $this->payoutBatches()->create($this->program, 'IDR', 'batch:1');
        $requests = [$this->approved('ALICE', '0.000001'), $this->approved('BOB', '99.999999')];

        foreach ($requests as $request) {
            $this->payoutBatches()->add($batch, $request);
        }

        $this->payoutBatches()->seal($batch, now());
        $this->payoutBatches()->startProcessing($batch, now());

        foreach ($requests as $request) {
            $this->payouts()->fail($request, 'bank-offline', now());
        }

        $this->assertSame(PayoutBatchStatus::Completed, $this->payoutBatches()->complete($batch, now())->status);
        $totals = PayoutBatchTotals::of($batch);
        $this->assertSame([2, '100', '0', 0, '0', 2, '100'], [$totals->count, $totals->requested->value(), $totals->reserved->value(), $totals->settledCount, $totals->settled->value(), $totals->failedCount, $totals->failed->value()]);
    }

    public function test_totals_beyond_a_64_bit_count_of_millionths_are_exact(): void
    {
        if ($this->app->make('db')->connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite overflows SUM past 64 bits (ADR-010); MySQL and PostgreSQL widen it.');
        }

        // Two payouts of the largest posting each, from two funded wallets.
        $largest = '9223372036854.775807';
        $batch = $this->payoutBatches()->create($this->program, 'IDR', 'batch:large');

        foreach (['ALICE', 'BOB'] as $code) {
            $this->fundedWallet($this->members[$code], $largest, "fund:large:{$code}");
            $this->payoutBatches()->add($batch, $this->approved($code, $largest, "payout:large:{$code}"));
        }

        $totals = PayoutBatchTotals::of($batch);

        $this->assertSame([2, '18446744073709.551614', '18446744073709.551614'], [$totals->count, $totals->requested->value(), $totals->reserved->value()]);
    }

    public function test_only_an_open_batch_is_cancelled_and_its_requests_stay_as_they_are(): void
    {
        $batch = $this->payoutBatches()->create($this->program, 'IDR', 'batch:1');
        $alice = $this->approved('ALICE', '10');
        $this->payoutBatches()->add($batch, $alice);

        $cancelled = $this->payoutBatches()->cancel($batch, CarbonImmutable::parse('2026-03-05'));

        $this->assertSame([PayoutBatchStatus::Cancelled, PayoutRequestStatus::Approved], [$cancelled->status, $alice->refresh()->status]);
        // An abandoned batch holds its request back no more.
        $this->assertSame(PayoutRequestStatus::Processing, $this->payouts()->startProcessing($alice, now())->status);

        $sealed = $this->payoutBatches()->create($this->program, 'IDR', 'batch:2');
        $this->payoutBatches()->add($sealed, $this->approved('BOB', '10'));
        $this->payoutBatches()->seal($sealed, now());

        $this->expectException(InvalidPayoutBatch::class);
        $this->expectExceptionMessage('only an open batch is cancelled');
        $this->payoutBatches()->cancel($sealed, now());
    }

    public function test_a_batch_whose_items_were_changed_is_refused(): void
    {
        $batch = $this->payoutBatches()->create($this->program, 'IDR', 'batch:1');
        $this->payoutBatches()->add($batch, $this->approved('ALICE', '10'));
        $this->payoutBatches()->add($batch, $this->approved('BOB', '10'));
        $this->payoutBatches()->seal($batch, now());

        // A raw write: a gap in the order.
        DB::table('mlm_payout_batch_items')->where('position', 2)->update(['position' => 5]);

        $this->expectException(CorruptPayoutBatch::class);
        $this->expectExceptionMessage('its positions are not 1, 2, 3');

        $this->payoutBatches()->startProcessing($batch, now());
    }

    public function test_batches_and_items_are_written_by_the_manager_alone(): void
    {
        $batch = $this->payoutBatches()->create($this->program, 'IDR', 'batch:1');
        $item = $this->payoutBatches()->add($batch, $this->approved('ALICE', '10'));

        foreach ([
            static fn () => PayoutBatch::query()->forceCreate([]),
            static fn () => $batch->forceFill(['status' => 'completed'])->save(),
            static fn () => $batch->delete(),
            static fn () => $item->forceFill(['position' => 3])->save(),
            static fn () => $item->delete(),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A batch row was written through Eloquent.');
            } catch (ImmutableCalculationRecord) {
            }
        }

        $this->assertSame(1, PayoutBatchItem::query()->count());
    }

    private function approved(string $code, string $amount, ?string $key = null): PayoutRequest
    {
        return $this->payouts()->approve($this->payoutRequest($this->members[$code], $amount, $key ?? "payout:{$code}:{$amount}"), now());
    }
}
