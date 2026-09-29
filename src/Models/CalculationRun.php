<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PandaBear\Mlm\Calculation\CalculationEngine;
use PandaBear\Mlm\Exceptions\ImmutableCalculationRecord;

/**
 * One successful calculation of one commission component of a validated
 * plan version, over a closed range [from, until), and the commissions it
 * found. Only a calculation that succeeded is stored: a stored run is a
 * complete result.
 *
 * It keeps what the calculation ran with — the strategy, the currency and
 * the exact source ledger account — and never changes. Written by
 * `CalculationEngine` alone; read-only through Eloquent.
 *
 * @property string $id
 * @property string $program_id
 * @property string $plan_version_id
 * @property string $plan_component_id
 * @property string $strategy
 * @property string $currency
 * @property string $source_ledger_account_id
 * @property CarbonImmutable $from_at
 * @property CarbonImmutable $until_at
 * @property string $idempotency_key
 * @property-read Program $program
 * @property-read PlanVersion $planVersion
 * @property-read PlanComponent $component
 * @property-read LedgerAccount $sourceAccount
 * @property-read Collection<int, Commission> $commissions
 */
final class CalculationRun extends MlmModel
{
    protected $table = 'mlm_calculation_runs';

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
     * @return BelongsTo<PlanComponent, $this>
     */
    public function component(): BelongsTo
    {
        return $this->belongsTo(PlanComponent::class, 'plan_component_id');
    }

    /**
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function sourceAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'source_ledger_account_id');
    }

    /**
     * In candidate key order.
     *
     * @return HasMany<Commission, $this>
     */
    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class)->orderBy('candidate_key');
    }

    protected static function booted(): void
    {
        $refuse = static function (): never {
            throw ImmutableCalculationRecord::outsideWriter(self::class, CalculationEngine::class);
        };

        self::creating($refuse);
        self::updating($refuse);
        self::deleting($refuse);
    }
}
