<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Binary\Correction;

use PandaBear\Mlm\Finance\FinancialAmount;

/**
 * One commission's financial share of one reversal's binary corrections
 * (ADR-026): how much of the pairing that earned it the reversal undid, on
 * top of what reversals ordered before it had undone, and how much of the
 * commission's stored amount that apportions to it.
 *
 * With A the stored amount, Q the quantity the pairing consumed and I the
 * quantity undone, the share of everything undone so far is
 * ⌊A × I ÷ Q⌋; this reversal's correction is that target after it less the
 * target before it. Quantities are whole millionths as decimal digits.
 */
final readonly class BinaryFinancialCorrectionShare
{
    /**
     * How much of the stored amount this reversal takes back: never
     * negative, and never more than the amount.
     */
    public FinancialAmount $amount;

    /**
     * @param  list<string>  $correctionIds  this reversal's corrections of the pairing, sorted
     * @param  string  $consumedQuantity  Q, in millionths
     * @param  string  $invalidatedBefore  I before this reversal, in millionths
     * @param  string  $invalidatedAfter  I with it, in millionths
     */
    public function __construct(
        public string $commissionId,
        public string $pairingResultId,
        public string $planComponentId,
        public array $correctionIds,
        public string $consumedQuantity,
        public FinancialAmount $originalAmount,
        public string $invalidatedBefore,
        public string $invalidatedAfter,
        public FinancialAmount $targetBefore,
        public FinancialAmount $targetAfter,
    ) {
        $this->amount = $targetAfter->add($targetBefore->negate());
    }
}
