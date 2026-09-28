<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use PandaBear\Mlm\Exceptions\InvalidVolumeEntry;
use PandaBear\Mlm\Volume\Quantity;

/**
 * One immutable record of volume: a member received a quantity of a volume
 * type, from a business source, effective at a moment. History, not a
 * balance, and not money.
 *
 * Written by `VolumeRecorder` alone. Creating, updating or deleting an entry
 * through the model is refused; a correction is a reversal entry. Raw
 * query-builder writes bypass these guards and are not a supported way to
 * keep the history's invariants.
 *
 * @property string $id
 * @property string $program_id
 * @property string $member_id
 * @property string $type
 * @property int $quantity_millionths
 * @property-read Quantity $quantity
 * @property string $source_type
 * @property string $source_id
 * @property string $idempotency_key
 * @property CarbonImmutable $effective_at
 * @property string|null $reversal_of_id
 * @property-read Program $program
 * @property-read Member $member
 * @property-read VolumeEntry|null $reversalOf
 * @property-read VolumeEntry|null $reversal
 */
final class VolumeEntry extends MlmModel
{
    /**
     * Integer digits one entry's quantity may have: up to
     * 999,999,999,999.999999, whose 18-digit count of millionths always fits
     * the signed 64-bit `quantity_millionths` column. A total may be larger.
     */
    public const MAX_INTEGER_DIGITS = 12;

    protected $table = 'mlm_volume_entries';

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
            'effective_at' => 'immutable_datetime',
        ];
    }

    /**
     * The exact decimal quantity. Negative for a reversal. Never a float.
     *
     * @return Attribute<Quantity, never>
     */
    protected function quantity(): Attribute
    {
        return Attribute::get(
            static fn (mixed $value, array $attributes): Quantity => Quantity::fromMillionths($attributes['quantity_millionths']),
        );
    }

    /**
     * @return BelongsTo<Program, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * The entry this one reverses, when it is a reversal.
     *
     * @return BelongsTo<VolumeEntry, $this>
     */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /**
     * The entry that reverses this one, when it has been reversed.
     *
     * @return HasOne<VolumeEntry, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    protected static function booted(): void
    {
        $refuse = static function (): never {
            throw InvalidVolumeEntry::outsideRecorder();
        };

        self::creating($refuse);
        self::updating($refuse);
        self::deleting($refuse);
    }
}
