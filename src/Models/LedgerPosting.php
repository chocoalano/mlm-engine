<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PandaBear\Mlm\Exceptions\ImmutableFinancialRecord;
use PandaBear\Mlm\Finance\FinancialAmount;
use PandaBear\Mlm\Finance\LedgerRecorder;

/**
 * One account's share of a ledger transaction: a signed amount — positive
 * raises the account's balance, negative lowers it. Written with its
 * transaction by `LedgerRecorder`, and never changed. Read-only through
 * Eloquent.
 *
 * @property string $id
 * @property string $ledger_transaction_id
 * @property string $ledger_account_id
 * @property int $amount_millionths
 * @property-read FinancialAmount $amount
 * @property-read LedgerTransaction $transaction
 * @property-read LedgerAccount $account
 */
final class LedgerPosting extends MlmModel
{
    /**
     * The largest count of millionths one posting holds, either way:
     * 9,223,372,036,854.775807. The signed 64-bit column could hold one
     * millionth more below zero, but that could not be reversed.
     */
    public const MAX_MILLIONTHS = '9223372036854775807';

    protected $table = 'mlm_ledger_postings';

    /**
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * The exact signed amount. Never a float.
     *
     * @return Attribute<FinancialAmount, never>
     */
    protected function amount(): Attribute
    {
        return Attribute::get(
            static fn (mixed $value, array $attributes): FinancialAmount => FinancialAmount::fromMillionths($attributes['amount_millionths']),
        );
    }

    /**
     * @return BelongsTo<LedgerTransaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'ledger_transaction_id');
    }

    /**
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    protected static function booted(): void
    {
        $refuse = static function (): never {
            throw ImmutableFinancialRecord::outsideWriter(self::class, LedgerRecorder::class);
        };

        self::creating($refuse);
        self::updating($refuse);
        self::deleting($refuse);
    }
}
