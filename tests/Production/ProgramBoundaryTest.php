<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Production;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use PandaBear\Mlm\Binary\BinarySide;
use PandaBear\Mlm\Matrix\MatrixNetworkManager;
use PandaBear\Mlm\Models\BinaryPlacementPosition;
use PandaBear\Mlm\Models\CommissionPeriod;
use PandaBear\Mlm\Models\LedgerTransaction;
use PandaBear\Mlm\Models\MatrixPlacementPosition;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\PayoutBatchItem;
use PandaBear\Mlm\Models\PayoutRequest;
use PandaBear\Mlm\Models\PlacementEdge;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Models\SponsorEdge;
use PandaBear\Mlm\Models\Wallet;
use PandaBear\Mlm\Period\CommissionPeriodManager;
use PandaBear\Mlm\Tests\DatabaseTestCase;
use PandaBear\Mlm\Tests\Panel\Concerns\BuildsOperations;
use Throwable;

/**
 * A program is the boundary (ADR-002): every writer refuses facts that span
 * two programs itself, before any foreign key could be asked, and writes
 * nothing.
 */
final class ProgramBoundaryTest extends DatabaseTestCase
{
    use BuildsOperations;

    private Program $other;

    private Member $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operatingProgram('BOB');
        $this->other = Program::factory()->create();
        $this->stranger = $this->members($this->other, 'STRANGER')['STRANGER'];
    }

    public function test_no_network_links_two_programs(): void
    {
        $this->app->make(MatrixNetworkManager::class)->configure($this->program, 2);
        $this->app->make(MatrixNetworkManager::class)->configure($this->other, 2);
        $before = [SponsorEdge::query()->count(), PlacementEdge::query()->count(), BinaryPlacementPosition::query()->count(), MatrixPlacementPosition::query()->count()];

        $this->refused(fn () => $this->genealogy()->assignSponsor($this->team['BOB'], $this->stranger));
        $this->refused(fn () => $this->placement()->place($this->team['BOB'], $this->stranger));
        $this->refused(fn () => $this->binary()->place($this->team['BOB'], $this->stranger, BinarySide::Left));
        $this->refused(fn () => $this->matrix()->place($this->team['BOB'], $this->stranger, 1));

        $this->assertSame($before, [SponsorEdge::query()->count(), PlacementEdge::query()->count(), BinaryPlacementPosition::query()->count(), MatrixPlacementPosition::query()->count()]);
    }

    public function test_no_period_or_ledger_movement_spans_two_programs(): void
    {
        $version = $this->activeVersion();
        $foreignSource = $this->systemAccounts()->openSystemAccount($this->other, 'IDR', 'commission.payable');

        $this->refused(fn () => $this->app->make(CommissionPeriodManager::class)->create($this->other, $version, $foreignSource, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-02-01'), CarbonImmutable::parse('2026-02-15'), 'period:1'));
        $this->refused(fn () => $this->app->make(CommissionPeriodManager::class)->create($this->program, $version, $foreignSource, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-02-01'), CarbonImmutable::parse('2026-02-15'), 'period:2'));
        $this->refused(fn () => $this->postLedger($this->program, [[$foreignSource, '-5'], [$this->source, '5']], 'cross:1', 'X-1'));

        $this->assertSame([0, 0], [CommissionPeriod::query()->count(), LedgerTransaction::query()->count()]);
    }

    public function test_no_payout_spends_another_programs_wallet_or_settles_through_its_account(): void
    {
        $this->fundedWallet($this->team['ALICE'], '100', 'fund:ALICE');
        $this->fundedWallet($this->stranger, '100', 'fund:STRANGER');
        $ownSettlement = $this->settlementAccount($this->team['ALICE']);
        $foreignSettlement = $this->settlementAccount($this->stranger);
        $foreignWallet = Wallet::query()->where('member_id', $this->stranger->id)->sole();

        $this->refused(fn () => $this->payouts()->request($this->team['ALICE'], $foreignWallet, $ownSettlement, '10', 'bank-account', 'ref', CarbonImmutable::parse('2026-03-01'), 'payout:1'));
        $this->refused(fn () => $this->payoutRequest($this->team['ALICE'], '10', 'payout:2', $foreignSettlement));

        $foreignRequest = $this->payouts()->approve($this->payoutRequest($this->stranger, '10', 'payout:3', $foreignSettlement), CarbonImmutable::parse('2026-03-01 11:00:00'));
        $batch = $this->payoutBatches()->create($this->program, 'IDR', 'batch:1');
        $this->refused(fn () => $this->payoutBatches()->add($batch, $foreignRequest));

        $this->assertSame([1, 0], [PayoutRequest::query()->count(), PayoutBatchItem::query()->count()]);
        $this->assertSame('100', $this->walletBalance($this->team['ALICE']));
    }

    private function refused(callable $write): void
    {
        try {
            $write();
        } catch (Throwable $exception) {
            $this->assertNotInstanceOf(QueryException::class, $exception, 'The database, not the writer, refused: '.$exception->getMessage());

            return;
        }

        $this->fail('A write spanning two programs was accepted.');
    }
}
