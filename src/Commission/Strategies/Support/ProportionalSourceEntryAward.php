<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission\Strategies\Support;

use PandaBear\Mlm\Commission\CommissionCandidate;
use PandaBear\Mlm\Commission\CommissionSourceReference;
use PandaBear\Mlm\Finance\FinancialAmount;
use PandaBear\Mlm\Finance\FinancialRoundingMode;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\VolumeEntry;

/**
 * @internal
 *
 * One proportional award earned by a sponsor at one depth above the member
 * of one source entry: the entry's quantity times the depth's amount per
 * unit, rounded on its own — per entry and depth, never after adding awards
 * up — as a candidate keyed, timed and traced like a fixed award, with the
 * calculation behind its amount. An award that rounds to zero is no
 * candidate. A strategy may add its own sections to the trace.
 */
final class ProportionalSourceEntryAward
{
    /**
     * @param  array<string, mixed>  $trace  the strategy's own trace sections
     */
    public static function candidate(
        string $strategy,
        VolumeEntry $entry,
        SourceEntryFilter $filter,
        Member $recipient,
        int $depth,
        FinancialAmount $unitAmount,
        FinancialRoundingMode $rounding,
        array $trace = [],
    ): ?CommissionCandidate {
        $award = ProportionalAwardCalculator::calculate($entry->quantity, $unitAmount, $rounding);

        if ($award->amount->isZero()) {
            return null;
        }

        return new CommissionCandidate(
            key: SourceEntryAward::key($entry, $depth),
            member: $recipient,
            amount: $award->amount,
            earnedAt: $entry->effective_at,
            trace: [
                ...$trace,
                'strategy' => $strategy,
                'source' => SourceEntryAward::source($entry),
                'minimum_quantity' => $filter->minimumQuantity->value(),
                'recipient' => [
                    'member_id' => (string) $recipient->getKey(),
                    'depth' => $depth,
                ],
                'calculation' => [
                    'quantity' => $entry->quantity->value(),
                    'unit_amount' => $unitAmount->value(),
                    'exact_amount' => $award->exactAmount,
                    'rounding' => $award->rounding->value,
                    'rounded' => $award->rounded,
                    'amount' => $award->amount->value(),
                ],
                'amount' => $award->amount->value(),
            ],
            source: CommissionSourceReference::volumeEntry($entry),
        );
    }
}
