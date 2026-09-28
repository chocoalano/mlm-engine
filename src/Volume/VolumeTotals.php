<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Volume;

use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use PandaBear\Mlm\Models\Member;

/**
 * Totals of a member's own volume entries. Nothing from the member's
 * genealogy: network totals belong to a later phase.
 */
final class VolumeTotals
{
    /**
     * The net quantity of one volume type recorded for the member —
     * originals and reversals together, so a reversed entry nets to zero.
     *
     * With a range, entries count by `effective_at` over [from, until): from
     * inclusive, until exclusive, so consecutive ranges never count an entry
     * twice. Either bound may be left open.
     */
    public function forMember(
        Member $member,
        string $type,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $until = null,
    ): Quantity {
        $type = VolumeInput::identifier('type', $type);

        $sum = $member->getConnection()->table('mlm_volume_entries')
            ->where('member_id', $member->getKey())
            ->where('type', $type)
            ->when($from !== null, static fn (Builder $query): Builder => $query->where('effective_at', '>=', VolumeInput::moment($from)))
            ->when($until !== null, static fn (Builder $query): Builder => $query->where('effective_at', '<', VolumeInput::moment($until)))
            ->sum('quantity_millionths');

        return Quantity::fromMillionths($sum);
    }
}
