<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PandaBear\Mlm\Binary\Pairing\BinaryPairingStateTransition;
use PandaBear\Mlm\Exceptions\ImmutableCalculationRecord;

/**
 * How far one binary pairing component has calculated (ADR-023): its state
 * began at `started_at` and covers everything before `through_at`, where
 * its next run must start.
 *
 * Moved forward only by a committed pairing run; read-only through
 * Eloquent.
 *
 * @property string $id
 * @property string $program_id
 * @property string $plan_component_id
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable $through_at
 * @property string $last_calculation_run_id
 * @property-read PlanComponent $component
 * @property-read CalculationRun $lastRun
 */
final class BinaryPairingCursor extends MlmModel
{
    protected $table = 'mlm_binary_pairing_cursors';

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
            'started_at' => 'immutable_datetime',
            'through_at' => 'immutable_datetime',
        ];
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
    public function lastRun(): BelongsTo
    {
        return $this->belongsTo(CalculationRun::class, 'last_calculation_run_id');
    }

    protected static function booted(): void
    {
        $refuse = static function (): never {
            throw ImmutableCalculationRecord::outsideWriter(self::class, BinaryPairingStateTransition::class);
        };

        self::creating($refuse);
        self::updating($refuse);
        self::deleting($refuse);
    }
}
