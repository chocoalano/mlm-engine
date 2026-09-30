<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PandaBear\Mlm\Calculation\CalculationBatchStatus;
use PandaBear\Mlm\Commission\HybridCalculationEngine;
use PandaBear\Mlm\Exceptions\ImmutableCalculationRecord;

/**
 * One hybrid calculation (ADR-028): every commission component of a plan
 * version, calculated over one range [from, until) and funded from one
 * source account, under one idempotency key — a run per component, linked
 * through its items.
 *
 * Its facts never change. Its status moves once, from `open` to
 * `completed`, when every item has its run; until then its commissions are
 * not posted. Written by `HybridCalculationEngine` alone; read-only through
 * Eloquent.
 *
 * @property string $id
 * @property string $program_id
 * @property string $plan_version_id
 * @property string $source_ledger_account_id
 * @property string $idempotency_key
 * @property CarbonImmutable $from_at
 * @property CarbonImmutable $until_at
 * @property CalculationBatchStatus $status
 * @property CarbonImmutable|null $completed_at
 * @property-read Program $program
 * @property-read PlanVersion $planVersion
 * @property-read LedgerAccount $sourceAccount
 * @property-read Collection<int, CalculationBatchItem> $items
 */
final class CalculationBatch extends MlmModel
{
    protected $table = 'mlm_calculation_batches';

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
            'from_at' => 'immutable_datetime',
            'until_at' => 'immutable_datetime',
            'status' => CalculationBatchStatus::class,
            'completed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Program, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /**
     * @return BelongsTo<PlanVersion, $this>
     */
    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class);
    }

    /**
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function sourceAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'source_ledger_account_id');
    }

    /**
     * Its components, in the order they are calculated.
     *
     * @return HasMany<CalculationBatchItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CalculationBatchItem::class)->orderBy('position');
    }

    protected static function booted(): void
    {
        $refuse = static function (): never {
            throw ImmutableCalculationRecord::outsideWriter(self::class, HybridCalculationEngine::class);
        };

        self::creating($refuse);
        self::updating($refuse);
        self::deleting($refuse);
    }
}
