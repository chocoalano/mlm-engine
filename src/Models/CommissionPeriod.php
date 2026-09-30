<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PandaBear\Mlm\Exceptions\ImmutableCalculationRecord;
use PandaBear\Mlm\Period\CommissionPeriodManager;
use PandaBear\Mlm\Period\CommissionPeriodStatus;

/**
 * A commission period (ADR-029): the calculation, review and release
 * boundary of one program — its plan version active when the period was
 * created, one range [from, until), one source account, and the earliest
 * moment its commissions may become available. A program's periods never
 * overlap.
 *
 * Its facts never change. Its status moves forward only — open,
 * calculated, finalized, released — through the period services, which
 * alone write it; read-only through Eloquent.
 *
 * @property string $id
 * @property string $program_id
 * @property string $plan_version_id
 * @property string $source_ledger_account_id
 * @property string $idempotency_key
 * @property CarbonImmutable $from_at
 * @property CarbonImmutable $until_at
 * @property CarbonImmutable $release_at
 * @property CommissionPeriodStatus $status
 * @property CarbonImmutable|null $input_closed_at
 * @property CarbonImmutable|null $calculated_at
 * @property CarbonImmutable|null $finalized_at
 * @property CarbonImmutable|null $released_at
 * @property-read Program $program
 * @property-read PlanVersion $planVersion
 * @property-read LedgerAccount $sourceAccount
 * @property-read Collection<int, CommissionPeriodRun> $runs
 */
final class CommissionPeriod extends MlmModel
{
    protected $table = 'mlm_commission_periods';

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
            'release_at' => 'immutable_datetime',
            'status' => CommissionPeriodStatus::class,
            'input_closed_at' => 'immutable_datetime',
            'calculated_at' => 'immutable_datetime',
            'finalized_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
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
     * Its commission components' runs, in the period's order.
     *
     * @return HasMany<CommissionPeriodRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(CommissionPeriodRun::class)->orderBy('position');
    }

    protected static function booted(): void
    {
        $refuse = static function (): never {
            throw ImmutableCalculationRecord::outsideWriter(self::class, CommissionPeriodManager::class.' and the period services');
        };

        self::creating($refuse);
        self::updating($refuse);
        self::deleting($refuse);
    }
}
