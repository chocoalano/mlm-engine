<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Binary;

use PandaBear\Mlm\Models\Member;

/**
 * A member reached through the binary tree, and how far away: depth 1 is a
 * binary parent or a binary child. No side: which leg of an anchor a
 * descendant is in is decided by the anchor's own child on that side, not by
 * the side the descendant itself was placed on.
 */
final readonly class BinaryRelative
{
    public function __construct(
        public Member $member,
        public int $depth,
    ) {}
}
