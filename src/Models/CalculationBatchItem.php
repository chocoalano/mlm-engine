<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PandaBear\Mlm\Commission\HybridCalculationEngine;
use PandaBear\Mlm\Exceptions\ImmutableCalculationRecord;

/**
 * One commission component of a hybrid batch (ADR-028): its place in the
 * batch's order, the idempotency key its run is calculated under — derived
 * from the batch and the component — and, once calculated, that run.
 *
 * Written with its batch; only the run is linked later, once, and never
 * replaced. Written by `HybridCalculationEngine` alone; read-only through
 * Eloquent.
 *
 * @property string $id
 * @property string $calculation_batch_id
 * @property string $plan_component_id
 * @property int $position
 * @property string $child_idempotency_key
 * @property string|null $calculation_run_id
 * @property-read CalculationBatch $batch
 * @property-read PlanComponent $component
 * @property-read CalculationRun|null $run
 */
final class CalculationBatchItem extends MlmModel
{
    protected $table = 'mlm_calculation_batch_items';

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
     * @return BelongsTo<CalculationBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(CalculationBatch::class, 'calculation_batch_id');
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
            throw ImmutableCalculationRecord::outsideWriter(self::class, HybridCalculationEngine::class);
        };

        self::creating($refuse);
        self::updating($refuse);
        self::deleting($refuse);
    }
}
