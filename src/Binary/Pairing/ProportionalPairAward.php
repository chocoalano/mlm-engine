<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Binary\Pairing;

use PandaBear\Mlm\Commission\Strategies\Support\ProportionalAwardCalculator;
use PandaBear\Mlm\Finance\FinancialAmount;
use PandaBear\Mlm\Finance\FinancialRoundingMode;
use PandaBear\Mlm\Volume\Quantity;

/**
 * @internal
 *
 * `binary.pairing.proportional`: the quantity the pairs consumed from each
 * leg times an amount per unit, rounded once by the configured mode — the
 * proportional math of ADR-020, unchanged.
 */
final readonly class ProportionalPairAward implements BinaryPairingAward
{
    public function __construct(
        public FinancialAmount $unitAmount,
        public FinancialRoundingMode $rounding,
    ) {}

    public function award(string $pairCount, Quantity $consumed): array
    {
        $award = ProportionalAwardCalculator::calculate($consumed, $this->unitAmount, $this->rounding);

        return [$award->amount, [
            'quantity' => $consumed->value(),
            'unit_amount' => $this->unitAmount->value(),
            'exact_amount' => $award->exactAmount,
            'rounding' => $award->rounding->value,
            'rounded' => $award->rounded,
            'amount' => $award->amount->value(),
        ]];
    }
}
