<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Genealogy;

use PandaBear\Mlm\Models\Member;

/**
 * A member reached through sponsorship, and how far away: depth 1 is a
 * direct sponsor or a directly sponsored member.
 */
final readonly class SponsorRelative
{
    public function __construct(
        public Member $member,
        public int $depth,
    ) {}
}
