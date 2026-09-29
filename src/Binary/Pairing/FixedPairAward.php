<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Binary\Pairing;

use PandaBear\Mlm\Finance\FinancialAmount;
use PandaBear\Mlm\Volume\Quantity;

/**
 * @internal
 *
 * `binary.pairing.fixed`: a fixed amount for every whole pair, so N pairs
 * earn exactly N × the amount — a whole multiple, never rounded. Whether it
 * fits one ledger posting is the candidate's rule.
 */
final readonly class FixedPairAward implements BinaryPairingAward
{
    public function __construct(public FinancialAmount $amountPerPair) {}

    public function award(string $pairCount, Quantity $consumed): array
    {
        $amount = FinancialAmount::fromMillionths(PairingArithmetic::multiply($pairCount, $this->amountPerPair->toMillionths()));

        return [$amount, [
            'amount_per_pair' => $this->amountPerPair->value(),
            'amount' => $amount->value(),
        ]];
    }
}
