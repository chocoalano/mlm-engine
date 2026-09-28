<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PandaBear\Mlm\Exceptions\InvalidSponsorAssignment;

/**
 * A direct sponsorship: `sponsor` sponsored `member`.
 *
 * Read-only through Eloquent. An edge is only meaningful together with the
 * genealogy paths it implies, so edges are written by
 * `SponsorGenealogy::assignSponsor()` alone, and creating, updating or
 * deleting one through the model is refused.
 *
 * @property string $id
 * @property string $member_id
 * @property string $sponsor_id
 * @property CarbonImmutable $assigned_at
 * @property-read Member $member
 * @property-read Member $sponsor
 */
final class SponsorEdge extends MlmModel
{
    protected $table = 'mlm_sponsor_edges';

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
     * The sponsored member.
     *
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    /**
     * @return BelongsTo<Member, $this>
     */
    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'sponsor_id');
    }

    protected static function booted(): void
    {
        $refuse = static function (): never {
            throw InvalidSponsorAssignment::outsideGenealogy();
        };

        self::creating($refuse);
        self::updating($refuse);
        self::deleting($refuse);
    }
}
