<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission;

use PandaBear\Mlm\Exceptions\InvalidCommissionAdjustment;
use PandaBear\Mlm\Finance\FinancialAmount;
use PandaBear\Mlm\Models\Commission;

/**
 * What a commission is still worth (ADR-026): its calculated amount plus
 * every adjustment recorded against it, whatever each did to its status.
 * Binary corrections are zero or negative and never add up to more than
 * the amount; a source clawback negates the amount whole. So the net lies
 * between zero and the calculated amount, and anything else is refused as
 * corrupt. Read-only: it changes nothing.
 */
final readonly class CommissionNetAmount
{
    /**
     * @throws InvalidCommissionAdjustment when the stored adjustments leave it out of range
     */
    public function of(Commission $commission): FinancialAmount
    {
        $adjustments = $commission->getConnection()->table('mlm_commission_adjustments')
            ->where('commission_id', $commission->getKey())
            ->pluck('amount_millionths')
            ->map(static fn (mixed $millionths): FinancialAmount => FinancialAmount::fromMillionths((string) $millionths))
            ->all();

        return self::from($commission, $adjustments);
    }

    /**
     * The net of the commission given its adjustments' amounts.
     *
     * @param  iterable<FinancialAmount>  $adjustments
     *
     * @throws InvalidCommissionAdjustment when they leave it out of range
     */
    public static function from(Commission $commission, iterable $adjustments): FinancialAmount
    {
        $net = $commission->amount->add(FinancialAmount::sum($adjustments));

        if ($net->isNegative() || $net->compare($commission->amount) > 0) {
            throw InvalidCommissionAdjustment::netOutOfRange((string) $commission->getKey(), $commission->amount->value(), $net->value());
        }

        return $net;
    }
}
