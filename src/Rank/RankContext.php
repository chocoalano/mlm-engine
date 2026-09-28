<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Rank;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Volume\VolumeInput;

/**
 * Who a rank ladder is evaluated for, and over which effective range: the
 * member, and an optional `[from, until)` — `from` included, `until`
 * excluded, either open — the range every rank of the ladder is qualified
 * over. With both bounds, `from` must come before `until`.
 */
final readonly class RankContext
{
    public ?CarbonImmutable $from;

    public ?CarbonImmutable $until;

    public function __construct(
        public Member $member,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $until = null,
    ) {
        [$this->from, $this->until] = VolumeInput::range($from, $until);
    }
}
