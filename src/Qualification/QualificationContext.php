<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Qualification;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Volume\VolumeInput;

/**
 * Who a rule is evaluated for, and over which effective range: the member,
 * and an optional `[from, until)` — `from` included, `until` excluded, either
 * open — the same range every metric condition of the rule receives. With
 * both bounds, `from` must come before `until`.
 *
 * Metric parameters are not part of it: they come from the rule's
 * conditions alone.
 */
final readonly class QualificationContext
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
