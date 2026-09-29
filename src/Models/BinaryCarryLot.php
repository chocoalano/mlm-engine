<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Models;

use Carbon\CarbonImmutable;
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
 * Binary carry, by source (ADR-023): one original volume entry as it fell,
 * at its own moment, in one side of `member`'s binary legs, for one pairing
 * component — its quantity, and how much of it is still unpaired.
 *
 * What it records never changes: component, member, side, entry, moment
 * and quantity. Only a pairing run changes `remaining` — consuming it, or
 * taking back an unpaired lot whose entry was reversed. Read-only through
 * Eloquent.
 *
 * @property string $id
 * @property string $program_id
 * @property string $plan_component_id
 * @property string $member_id
 * @property-read BinarySide $side
 * @property string $source_volume_entry_id
 * @property CarbonImmutable $source_effective_at
 * @property int $quantity_millionths
 * @property int $remaining_millionths
 * @property string|null $reversed_by_volume_entry_id
 * @property-read Quantity $quantity
 * @property-read Quantity $remaining
 * @property-read Member $member
 * @property-read VolumeEntry $sourceEntry
 * @property-read VolumeEntry|null $reversalEntry
 * @property-read Collection<int, BinaryPairingAllocation> $allocations
 */
final class BinaryCarryLot extends MlmModel
{
    protected $table = 'mlm_binary_carry_lots';

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
            'source_effective_at' => 'immutable_datetime',
            'quantity_millionths' => 'integer',
            'remaining_millionths' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * @return BelongsTo<VolumeEntry, $this>
     */
    public function sourceEntry(): BelongsTo
    {
        return $this->belongsTo(VolumeEntry::class, 'source_volume_entry_id');
    }

    /**
     * @return BelongsTo<VolumeEntry, $this>
     */
    public function reversalEntry(): BelongsTo
    {
        return $this->belongsTo(VolumeEntry::class, 'reversed_by_volume_entry_id');
    }

    /**
     * What pairings consumed of it, in the order they were written.
     *
     * @return HasMany<BinaryPairingAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(BinaryPairingAllocation::class, 'binary_carry_lot_id')->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return Attribute<Quantity, never>
     */
    protected function quantity(): Attribute
    {
        return Attribute::get(fn (): Quantity => Quantity::fromMillionths($this->quantity_millionths));
    }

    /**
     * @return Attribute<Quantity, never>
     */
    protected function remaining(): Attribute
    {
        return Attribute::get(fn (): Quantity => Quantity::fromMillionths($this->remaining_millionths));
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
