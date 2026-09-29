<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PandaBear\Mlm\Commission\CommissionAdjustmentEngine;
use PandaBear\Mlm\Commission\CommissionAdjustmentOutcome;
use PandaBear\Mlm\Commission\CommissionTrace;
use PandaBear\Mlm\Exceptions\ImmutableCalculationRecord;
use PandaBear\Mlm\Finance\FinancialAmount;

/**
 * Why and how a calculated commission was corrected (ADR-021): its type —
 * `clawback` — the record that required it, the signed amount it corrects
 * by, when, and what it did to the commission. Written once, when the
 * correction completes, by `CommissionAdjustmentEngine`; read-only through
 * Eloquent. The commission's calculated facts are never changed: the
 * correction is its lifecycle and the ledger's reversal, recorded here.
 *
 * @property string $id
 * @property string $program_id
 * @property string $commission_id
 * @property string $type
 * @property string $source_type
 * @property string $source_id
 * @property int $amount_millionths
 * @property-read FinancialAmount $amount
 * @property CarbonImmutable $occurred_at
 * @property CommissionAdjustmentOutcome $outcome
 * @property string|null $ledger_transaction_id
 * @property-read array<array-key, mixed> $trace
 * @property-read Program $program
 * @property-read Commission $commission
 * @property-read LedgerTransaction|null $ledgerTransaction
 */
final class CommissionAdjustment extends MlmModel
{
    protected $table = 'mlm_commission_adjustments';

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
            'occurred_at' => 'immutable_datetime',
            'outcome' => CommissionAdjustmentOutcome::class,
        ];
    }

    /**
     * The exact signed amount: negative for a clawback. Never a float.
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
     * @return Attribute<array<array-key, mixed>, never>
     */
    protected function trace(): Attribute
    {
        return Attribute::get(static fn (mixed $value): array => CommissionTrace::decode((string) $value));
    }

    /**
     * @return BelongsTo<Program, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /**
     * @return BelongsTo<Commission, $this>
     */
    public function commission(): BelongsTo
    {
        return $this->belongsTo(Commission::class);
    }

    /**
     * @return BelongsTo<LedgerTransaction, $this>
     */
    public function ledgerTransaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class);
    }

    protected static function booted(): void
    {
        $refuse = static function (): never {
            throw ImmutableCalculationRecord::outsideWriter(self::class, CommissionAdjustmentEngine::class);
        };

        self::creating($refuse);
        self::updating($refuse);
        self::deleting($refuse);
    }
}
