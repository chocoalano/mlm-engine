<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Matrix;

use PandaBear\Mlm\Models\Member;

/**
 * A member reached through the matrix, and how far away: depth 1 is a
 * matrix parent or a matrix child.
 */
final readonly class MatrixRelative
{
    public function __construct(
        public Member $member,
        public int $depth,
    ) {}
}
