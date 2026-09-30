<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PandaBear\Mlm\Exceptions\ImmutableCalculationRecord;
use PandaBear\Mlm\Period\CommissionPeriodCalculator;

/**
 * The run that calculated one commission component of a commission period
 * (ADR-029), in its place in the period's order. Written once, by
 * `CommissionPeriodCalculator`; read-only through Eloquent.
 *
 * @property string $id
 * @property string $commission_period_id
 * @property string $plan_component_id
 * @property string $calculation_run_id
 * @property int $position
 * @property-read CommissionPeriod $period
 * @property-read PlanComponent $component
 * @property-read CalculationRun $run
 */
final class CommissionPeriodRun extends MlmModel
{
    protected $table = 'mlm_commission_period_runs';

    /**
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /**
     * @return BelongsTo<CommissionPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(CommissionPeriod::class, 'commission_period_id');
    }

    /**
     * @return BelongsTo<PlanComponent, $this>
     */
    public function component(): BelongsTo
    {
        return $this->belongsTo(PlanComponent::class, 'plan_component_id');
    }

    /**
     * @return BelongsTo<CalculationRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(CalculationRun::class, 'calculation_run_id');
    }

    protected static function booted(): void
    {
        $refuse = static function (): never {
            throw ImmutableCalculationRecord::outsideWriter(self::class, CommissionPeriodCalculator::class);
        };

        self::creating($refuse);
        self::updating($refuse);
        self::deleting($refuse);
    }
}
