<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission\Strategies\Support;

use PandaBear\Mlm\Finance\FinancialAmount;
use PandaBear\Mlm\Finance\FinancialRoundingMode;

/**
 * @internal
 *
 * A quantity times an amount per unit: the exact product — up to twelve
 * decimal places, as canonical text, since money is kept to six — and the
 * six-decimal amount the rounding mode made of it.
 */
final readonly class ProportionalAwardResult
{
    public function __construct(
        public FinancialAmount $amount,
        public string $exactAmount,
        public FinancialRoundingMode $rounding,
        public bool $rounded,
    ) {}
}
