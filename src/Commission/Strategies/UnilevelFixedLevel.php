<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission\Strategies;

use PandaBear\Mlm\Finance\FinancialAmount;

/**
 * @internal
 *
 * One rewarded sponsor depth of `unilevel.fixed`, and its fixed award.
 */
final readonly class UnilevelFixedLevel
{
    public function __construct(
        public int $depth,
        public FinancialAmount $amount,
    ) {}
}
