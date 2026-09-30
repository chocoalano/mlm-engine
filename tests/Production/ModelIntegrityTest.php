<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Production;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use PandaBear\Mlm\Commission\CommissionStatus;
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
use PandaBear\Mlm\Models\PayoutBatch;
use PandaBear\Mlm\Models\PayoutBatchItem;
use PandaBear\Mlm\Models\PayoutRequest;
use PandaBear\Mlm\Models\PlacementEdge;
use PandaBear\Mlm\Models\PlanComponent;
use PandaBear\Mlm\Models\PlanRule;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Models\SponsorEdge;
use PandaBear\Mlm\Models\VolumeEntry;
use PandaBear\Mlm\Models\Wallet;
use PandaBear\Mlm\Payout\PayoutRequestStatus;
use PandaBear\Mlm\Tests\DatabaseTestCase;
use Throwable;

/**
 * The write surface of every history and financial record: none is created,
 * changed or deleted through Eloquent — each has one writer — and the facts
 * the architecture decided stay decided: no stored balance, no paid
 * commission.
 */
final class ModelIntegrityTest extends DatabaseTestCase
{
    /**
     * @var list<class-string<Model>>
     */
    private const IMMUTABLE = [
        PlanComponent::class, PlanRule::class,
        SponsorEdge::class, PlacementEdge::class, BinaryPlacementPosition::class, MatrixNetwork::class, MatrixPlacementPosition::class,
        VolumeEntry::class,
        LedgerAccount::class, LedgerTransaction::class, LedgerPosting::class, Wallet::class,
        CalculationRun::class, CalculationBatch::class, CalculationBatchItem::class, Commission::class, CommissionAdjustment::class,
        BinaryCarryLot::class, BinaryPairingAllocation::class, BinaryPairingCorrection::class, BinaryPairingCursor::class,
        BinaryPairingRestoration::class, BinaryPairingResult::class,
        CommissionPeriod::class, CommissionPeriodRun::class,
        PayoutRequest::class, PayoutBatch::class, PayoutBatchItem::class,
    ];

    public function test_no_history_or_financial_record_is_written_through_eloquent(): void
    {
        foreach (self::IMMUTABLE as $model) {
            $this->assertRefused(static fn () => (new $model)->forceFill(['id' => (new $model)->newUniqueId()])->save(), "{$model} was created through Eloquent.");

            $stored = (new $model)->newFromBuilder(['id' => (new $model)->newUniqueId(), 'created_at' => '2026-01-01 00:00:00']);
            $this->assertRefused(static fn () => $stored->forceFill(['created_at' => '2026-01-02 00:00:00'])->save(), "{$model} was updated through Eloquent.");
            $this->assertRefused(static fn () => $stored->delete(), "{$model} was deleted through Eloquent.");

            $this->assertSame(0, $model::query()->count(), "{$model} reached the database.");
        }
    }

    /**
     * A plan version is created and moved by its lifecycle alone, and only a
     * draft can be deleted.
     */
    public function test_a_plan_versions_lifecycle_cannot_be_written_around(): void
    {
        $this->assertRefused(static fn () => (new PlanVersion)->forceFill(['id' => (new PlanVersion)->newUniqueId(), 'status' => 'active'])->save(), 'A version was created active.');

        $active = (new PlanVersion)->newFromBuilder(['id' => (new PlanVersion)->newUniqueId(), 'status' => 'active', 'version' => 1]);
        $this->assertRefused(static fn () => $active->forceFill(['status' => 'draft'])->save(), 'A version was moved through Eloquent.');
        $this->assertRefused(static fn () => (new PlanVersion)->newFromBuilder(['id' => $active->id, 'status' => 'active', 'version' => 1])->delete(), 'An active version was deleted.');
    }

    public function test_a_wallet_balance_is_never_stored(): void
    {
        foreach (['mlm_wallets', 'mlm_ledger_accounts'] as $table) {
            foreach (Schema::getColumnListing($table) as $column) {
                $this->assertDoesNotMatchRegularExpression('/balance|available|reserved|pending/', $column, "{$table} stores [{$column}].");
            }
        }
    }

    /**
     * External settlement belongs to the payout request; a commission is
     * never "paid".
     */
    public function test_a_commission_is_never_paid(): void
    {
        $this->assertSame(
            ['calculated', 'pending', 'approved', 'held', 'available', 'posted', 'cancelled', 'reversed'],
            array_map(static fn (CommissionStatus $status): string => $status->value, CommissionStatus::cases()),
        );
        $this->assertNull(CommissionStatus::tryFrom('paid'));
        $this->assertFalse(Schema::hasColumn('mlm_commissions', 'paid_at'));
        $this->assertSame(PayoutRequestStatus::Settled, PayoutRequestStatus::from('settled'));
        $this->assertFalse(Schema::hasColumn('mlm_payout_requests', 'commission_id'));
    }

    private function assertRefused(callable $write, string $message): void
    {
        try {
            $write();
        } catch (Throwable) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail($message);
    }
}
