<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PandaBear\Mlm\Binary\BinarySide;
use PandaBear\Mlm\Binary\Pairing\BinaryPairingStateTransition;
use PandaBear\Mlm\Exceptions\CorruptBinaryPlacement;
use PandaBear\Mlm\Exceptions\ImmutableCalculationRecord;
use PandaBear\Mlm\Volume\Quantity;

/**
 * What a correction gave back (ADR-025): so much of one historical
 * allocation on the opposite side of an undone pair, returned to the carry
 * lot it was drawn from. Written once, never changed; read-only through
 * Eloquent.
 *
 * @property string $id
 * @property string $binary_pairing_correction_id
 * @property string $restored_allocation_id
 * @property string $binary_carry_lot_id
 * @property-read BinarySide $side
 * @property int $quantity_millionths
 * @property-read Quantity $quantity
 * @property-read BinaryPairingCorrection $correction
 * @property-read BinaryPairingAllocation $restoredAllocation
 * @property-read BinaryCarryLot $carryLot
 */
final class BinaryPairingRestoration extends MlmModel
{
    protected $table = 'mlm_binary_pairing_restorations';

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
     * @return BelongsTo<BinaryPairingCorrection, $this>
     */
    public function correction(): BelongsTo
    {
        return $this->belongsTo(BinaryPairingCorrection::class, 'binary_pairing_correction_id');
    }

    /**
     * @return BelongsTo<BinaryPairingAllocation, $this>
     */
    public function restoredAllocation(): BelongsTo
    {
        return $this->belongsTo(BinaryPairingAllocation::class, 'restored_allocation_id');
    }

    /**
     * @return BelongsTo<BinaryCarryLot, $this>
     */
    public function carryLot(): BelongsTo
    {
        return $this->belongsTo(BinaryCarryLot::class, 'binary_carry_lot_id');
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
    protected function side(): Attribute
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
