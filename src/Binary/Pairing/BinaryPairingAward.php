<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Binary\Pairing;

use PandaBear\Mlm\Finance\FinancialAmount;
use PandaBear\Mlm\Volume\Quantity;

/**
 * @internal
 *
 * What a binary member earns for the pairs one run formed: the one thing
 * that differs between the fixed and proportional pairing strategies.
 */
interface BinaryPairingAward
{
    /**
     * The award for `$pairCount` pairs, consuming `$consumed` from each leg,
     * and how it was calculated — as trace data. The amount may be zero: a
     * proportional award can round to nothing.
     *
     * @return array{FinancialAmount, array<string, mixed>}
     */
    public function award(string $pairCount, Quantity $consumed): array;
}
