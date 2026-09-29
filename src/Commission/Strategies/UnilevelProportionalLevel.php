<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission\Strategies;

use PandaBear\Mlm\Finance\FinancialAmount;

/**
 * @internal
 *
 * One rewarded sponsor depth of `unilevel.proportional`, and its amount per
 * unit of source quantity.
 */
final readonly class UnilevelProportionalLevel
{
    public function __construct(
        public int $depth,
        public FinancialAmount $unitAmount,
    ) {}
}
