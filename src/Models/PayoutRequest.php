<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use PandaBear\Mlm\Exceptions\ImmutableCalculationRecord;
use PandaBear\Mlm\Finance\FinancialAmount;
use PandaBear\Mlm\Payout\PayoutManager;
use PandaBear\Mlm\Payout\PayoutRequestStatus;

/**
 * A request to pay part of a member's wallet out (ADR-030): so much, in the
 * wallet's currency, to a destination the application names, moving
 * through the program's settlement account. Its facts never change; its
 * status moves through `PayoutManager` — which alone writes it — with the
 * ledger transactions that reserved its amount and, if it failed, returned
 * it. Read-only through Eloquent.
 *
 * @property string $id
 * @property string $program_id
 * @property string $member_id
 * @property string $wallet_id
 * @property string $settlement_ledger_account_id
 * @property string $currency
 * @property int $amount_millionths
 * @property-read FinancialAmount $amount
 * @property string $destination_type
 * @property string $destination_reference
 * @property string $idempotency_key
 * @property PayoutRequestStatus $status
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $processing_at
 * @property CarbonImmutable|null $settled_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $settlement_reference
 * @property string|null $failure_reason
 * @property string|null $reservation_ledger_transaction_id
 * @property string|null $refund_ledger_transaction_id
 * @property-read Program $program
 * @property-read Member $member
 * @property-read Wallet $wallet
 * @property-read LedgerAccount $settlementAccount
 * @property-read LedgerTransaction|null $reservation
 * @property-read LedgerTransaction|null $refund
 * @property-read PayoutBatchItem|null $batchItem
 */
final class PayoutRequest extends MlmModel
{
    protected $table = 'mlm_payout_requests';

    /**
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_millionths' => 'integer',
            'status' => PayoutRequestStatus::class,
            'requested_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'processing_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return Attribute<FinancialAmount, never>
     */
    protected function amount(): Attribute
    {
        return Attribute::get(static fn (mixed $value, array $attributes): FinancialAmount => FinancialAmount::fromMillionths($attributes['amount_millionths']));
    }

    /**
     * @return BelongsTo<Program, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * @return BelongsTo<Wallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /**
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function settlementAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'settlement_ledger_account_id');
    }

    /**
     * @return BelongsTo<LedgerTransaction, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'reservation_ledger_transaction_id');
    }

    /**
     * @return BelongsTo<LedgerTransaction, $this>
     */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'refund_ledger_transaction_id');
    }

    /**
     * @return HasOne<PayoutBatchItem, $this>
     */
    public function batchItem(): HasOne
    {
        return $this->hasOne(PayoutBatchItem::class);
    }

    protected static function booted(): void
    {
        $refuse = static function (): never {
            throw ImmutableCalculationRecord::outsideWriter(self::class, PayoutManager::class);
        };

        self::creating($refuse);
        self::updating($refuse);
        self::deleting($refuse);
    }
}
