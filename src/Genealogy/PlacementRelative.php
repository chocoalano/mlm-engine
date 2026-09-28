<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Genealogy;

use PandaBear\Mlm\Models\Member;

/**
 * A member reached through placement, and how far away: depth 1 is a direct
 * placement parent or a member placed directly underneath.
 */
final readonly class PlacementRelative
{
    public function __construct(
        public Member $member,
        public int $depth,
    ) {}
}
