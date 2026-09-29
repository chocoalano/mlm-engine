<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PandaBear\Mlm\Calculation\CalculationEngine;
use PandaBear\Mlm\Commission\CommissionLifecycle;
use PandaBear\Mlm\Commission\CommissionPoster;
use PandaBear\Mlm\Commission\CommissionStatus;
use PandaBear\Mlm\Commission\CommissionTrace;
use PandaBear\Mlm\Exceptions\ImmutableCalculationRecord;
use PandaBear\Mlm\Finance\FinancialAmount;

/**
 * One commission a calculation run found: a member earned an exact, positive
 * amount in the run's currency, at a business moment, for the reasons its
 * trace records.
 *
 * What was calculated never changes. Its status moves through
 * `CommissionLifecycle` — pending, approved, or cancelled before posting —
 * and `CommissionPoster`, which alone posts it to the ledger and reverses it.
 * Read-only through Eloquent. Raw query-builder writes bypass this and are
 * not a supported way to keep its invariants.
 *
 * @property string $id
 * @property string $calculation_run_id
 * @property string $program_id
 * @property string $member_id
 * @property string $candidate_key
 * @property string $currency
 * @property int $amount_millionths
 * @property-read FinancialAmount $amount
 * @property CarbonImmutable $earned_at
 * @property-read array<array-key, mixed> $trace
 * @property CommissionStatus $status
 * @property CarbonImmutable|null $pending_at
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $posted_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $reversed_at
 * @property string|null $ledger_transaction_id
 * @property string|null $reversal_ledger_transaction_id
 * @property-read CalculationRun $run
 * @property-read Program $program
 * @property-read Member $member
 * @property-read LedgerTransaction|null $ledgerTransaction
 * @property-read LedgerTransaction|null $reversalLedgerTransaction
 */
final class Commission extends MlmModel
{
    protected $table = 'mlm_commissions';

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
            'earned_at' => 'immutable_datetime',
            'status' => CommissionStatus::class,
            'pending_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'posted_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
        ];
    }

    /**
     * The exact amount. Never a float.
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
     * The trace, with every object's keys in canonical order, whatever order
     * the database keeps them in.
     *
     * @return Attribute<array<array-key, mixed>, never>
     */
    protected function trace(): Attribute
    {
        return Attribute::get(static fn (mixed $value): array => CommissionTrace::decode((string) $value));
    }

    /**
     * @return BelongsTo<CalculationRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(CalculationRun::class, 'calculation_run_id');
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
     * @return BelongsTo<LedgerTransaction, $this>
     */
    public function ledgerTransaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class);
    }

    /**
     * @return BelongsTo<LedgerTransaction, $this>
     */
    public function reversalLedgerTransaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'reversal_ledger_transaction_id');
    }

    protected static function booted(): void
    {
        $refuse = static function (): never {
            throw ImmutableCalculationRecord::outsideWriter(self::class, CalculationEngine::class.', '.CommissionLifecycle::class.' and '.CommissionPoster::class);
        };

        self::creating($refuse);
        self::updating($refuse);
        self::deleting($refuse);
    }
}
