<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use PandaBear\Mlm\Binary\BinarySide;
use PandaBear\Mlm\Exceptions\CorruptBinaryPlacement;
use PandaBear\Mlm\Exceptions\InvalidBinaryPlacement;

/**
 * A generic placement edge in the binary overlay (ADR-022): its member is
 * its parent's binary child on `side`, from `assigned_at` on.
 *
 * Read-only through Eloquent. A position is only meaningful together with
 * the binary paths it implies, so positions are written by
 * `BinaryPlacementManager` alone, and creating, updating or deleting one
 * through the model is refused. A side never changes and a position is
 * never removed.
 *
 * @property string $id
 * @property string $placement_edge_id
 * @property string $parent_id
 * @property-read BinarySide $side
 * @property CarbonImmutable $assigned_at
 * @property-read PlacementEdge $placementEdge
 * @property-read Member $parent
 * @property-read Member|null $member
 */
final class BinaryPlacementPosition extends MlmModel
{
    protected $table = 'mlm_binary_placement_positions';

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
            'assigned_at' => 'immutable_datetime',
        ];
    }

    /**
     * The generic edge this position enrols.
     *
     * @return BelongsTo<PlacementEdge, $this>
     */
    public function placementEdge(): BelongsTo
    {
        return $this->belongsTo(PlacementEdge::class, 'placement_edge_id');
    }

    /**
     * The binary parent: always the edge's placement parent.
     *
     * @return BelongsTo<Member, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'parent_id');
    }

    /**
     * The placed member, through the edge: not stored twice.
     *
     * @return HasOneThrough<Member, PlacementEdge, $this>
     */
    public function member(): HasOneThrough
    {
        return $this->hasOneThrough(Member::class, PlacementEdge::class, 'id', 'id', 'placement_edge_id', 'member_id');
    }

    /**
     * Exactly as stored: a side spelled any other way is refused, not read.
     *
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
            throw InvalidBinaryPlacement::outsideManager();
        };

        self::creating($refuse);
        self::updating($refuse);
        self::deleting($refuse);
    }
}
