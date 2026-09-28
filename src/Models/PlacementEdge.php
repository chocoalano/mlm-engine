<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PandaBear\Mlm\Exceptions\InvalidPlacementAssignment;

/**
 * A direct placement: `member` is placed under its placement `parent`.
 * Independent of sponsorship.
 *
 * Read-only through Eloquent. An edge is only meaningful together with the
 * placement paths it implies, so edges are written by
 * `PlacementGenealogy::place()` alone, and creating, updating or deleting one
 * through the model is refused.
 *
 * @property string $id
 * @property string $member_id
 * @property string $parent_id
 * @property CarbonImmutable $placed_at
 * @property-read Member $member
 * @property-read Member $parent
 */
final class PlacementEdge extends MlmModel
{
    protected $table = 'mlm_placement_edges';

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
            'placed_at' => 'immutable_datetime',
        ];
    }

    /**
     * The placed member.
     *
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    /**
     * The member it is placed under.
     *
     * @return BelongsTo<Member, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'parent_id');
    }

    protected static function booted(): void
    {
        $refuse = static function (): never {
            throw InvalidPlacementAssignment::outsideGenealogy();
        };

        self::creating($refuse);
        self::updating($refuse);
        self::deleting($refuse);
    }
}
