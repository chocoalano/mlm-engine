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
 * So much of one carry lot consumed by one pairing result (ADR-023): the
 * link from a paired commission back to the source entries it was paid on.
 * Written once, never changed; read-only through Eloquent.
 *
 * @property string $id
 * @property string $binary_pairing_result_id
 * @property string $binary_carry_lot_id
 * @property-read BinarySide $side
 * @property int $quantity_millionths
 * @property-read Quantity $quantity
 * @property-read BinaryPairingResult $result
 * @property-read BinaryCarryLot $carryLot
 */
final class BinaryPairingAllocation extends MlmModel
{
    protected $table = 'mlm_binary_pairing_allocations';

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
            'quantity_millionths' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<BinaryPairingResult, $this>
     */
    public function result(): BelongsTo
    {
        return $this->belongsTo(BinaryPairingResult::class, 'binary_pairing_result_id');
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
