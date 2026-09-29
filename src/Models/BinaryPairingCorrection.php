<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PandaBear\Mlm\Binary\BinarySide;
use PandaBear\Mlm\Binary\Pairing\BinaryPairingStateTransition;
use PandaBear\Mlm\Exceptions\CorruptBinaryPlacement;
use PandaBear\Mlm\Exceptions\ImmutableCalculationRecord;
use PandaBear\Mlm\Volume\Quantity;

/**
 * Part of an earlier pair undone (ADR-025): so much of one historical
 * allocation stopped counting because its source entry was reversed, in the
 * run the reversal fell in. The allocation, its pairing result and its
 * commission never change; the quantity went back to the opposite side
 * through its restorations. Written once, never changed; read-only through
 * Eloquent.
 *
 * @property string $id
 * @property string $program_id
 * @property string $plan_component_id
 * @property string $calculation_run_id
 * @property string $reversal_volume_entry_id
 * @property string $original_volume_entry_id
 * @property string $binary_pairing_result_id
 * @property string $invalidated_allocation_id
 * @property string $member_id
 * @property-read BinarySide $invalidated_side
 * @property int $quantity_millionths
 * @property string|null $commission_id
 * @property-read Quantity $quantity
 * @property-read CalculationRun $run
 * @property-read BinaryPairingResult $result
 * @property-read BinaryPairingAllocation $invalidatedAllocation
 * @property-read Commission|null $commission
 * @property-read Collection<int, BinaryPairingRestoration> $restorations
 */
final class BinaryPairingCorrection extends MlmModel
{
    protected $table = 'mlm_binary_pairing_corrections';

    /**
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity_millionths' => 'integer'];
    }

    /**
     * @return BelongsTo<CalculationRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(CalculationRun::class, 'calculation_run_id');
    }

    /**
     * @return BelongsTo<BinaryPairingResult, $this>
     */
    public function result(): BelongsTo
    {
        return $this->belongsTo(BinaryPairingResult::class, 'binary_pairing_result_id');
    }

    /**
     * @return BelongsTo<BinaryPairingAllocation, $this>
     */
    public function invalidatedAllocation(): BelongsTo
    {
        return $this->belongsTo(BinaryPairingAllocation::class, 'invalidated_allocation_id');
    }

    /**
     * @return BelongsTo<Commission, $this>
     */
    public function commission(): BelongsTo
    {
        return $this->belongsTo(Commission::class);
    }

    /**
     * @return HasMany<BinaryPairingRestoration, $this>
     */
    public function restorations(): HasMany
    {
        return $this->hasMany(BinaryPairingRestoration::class, 'binary_pairing_correction_id')->orderBy('id');
    }

    /**
     * @return Attribute<Quantity, never>
     */
    protected function quantity(): Attribute
    {
        return Attribute::get(fn (): Quantity => Quantity::fromMillionths($this->quantity_millionths));
    }

    /**
     * @return Attribute<BinarySide, never>
     */
    protected function invalidatedSide(): Attribute
    {
        return Attribute::get(fn (mixed $value): BinarySide => BinarySide::parse($value)
            ?? throw CorruptBinaryPlacement::side((string) $this->getKey(), $value));
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
