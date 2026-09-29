<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PandaBear\Mlm\Binary\Pairing\BinaryPairingStateTransition;
use PandaBear\Mlm\Exceptions\ImmutableCalculationRecord;

/**
 * What one binary pairing run did for one binary member (ADR-023): carry
 * before, added, taken back by reversals, available, the pairs formed and
 * the quantity they consumed from each side, and carry after — with the
 * commission it earned, if any. Quantities are exact decimal text of any
 * size; `pair_count` a whole number.
 *
 * Written once, with its run; never changed. Read-only through Eloquent.
 *
 * @property string $id
 * @property string $calculation_run_id
 * @property string $program_id
 * @property string $plan_component_id
 * @property string $member_id
 * @property string $left_carry_before
 * @property string $right_carry_before
 * @property string $left_added
 * @property string $right_added
 * @property string $left_reversed
 * @property string $right_reversed
 * @property string $left_available
 * @property string $right_available
 * @property string $pair_quantity
 * @property string $pair_count
 * @property string $consumed_quantity
 * @property string $left_carry_after
 * @property string $right_carry_after
 * @property string|null $commission_id
 * @property-read CalculationRun $run
 * @property-read Member $member
 * @property-read Commission|null $commission
 * @property-read Collection<int, BinaryPairingAllocation> $allocations
 */
final class BinaryPairingResult extends MlmModel
{
    protected $table = 'mlm_binary_pairing_results';

    /**
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @return BelongsTo<CalculationRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(CalculationRun::class, 'calculation_run_id');
    }

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * @return BelongsTo<Commission, $this>
     */
    public function commission(): BelongsTo
    {
        return $this->belongsTo(Commission::class);
    }

    /**
     * The carry it consumed, lot by lot: each side oldest first.
     *
     * @return HasMany<BinaryPairingAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(BinaryPairingAllocation::class, 'binary_pairing_result_id')->orderBy('id');
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
