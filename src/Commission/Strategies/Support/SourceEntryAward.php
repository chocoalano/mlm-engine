<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission\Strategies\Support;

use PandaBear\Mlm\Commission\CommissionCandidate;
use PandaBear\Mlm\Commission\CommissionSourceReference;
use PandaBear\Mlm\Finance\FinancialAmount;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\VolumeEntry;

/**
 * @internal
 *
 * One fixed award earned by a sponsor at one depth above the member of one
 * source entry, as a candidate: keyed by the entry and the depth — so the
 * same entry and depth are always the same candidate, whatever order they
 * are found in — earned when the entry took effect, and traced back to it.
 * A strategy may add its own sections to the trace; the shared ones are
 * never replaced.
 */
final class SourceEntryAward
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
        FinancialAmount $amount,
        array $trace = [],
    ): CommissionCandidate {
        return new CommissionCandidate(
            key: self::key($entry, $depth),
            member: $recipient,
            amount: $amount,
            earnedAt: $entry->effective_at,
            trace: [
                ...$trace,
                'strategy' => $strategy,
                'source' => self::source($entry),
                'minimum_quantity' => $filter->minimumQuantity->value(),
                'recipient' => [
                    'member_id' => (string) $recipient->getKey(),
                    'depth' => $depth,
                ],
                'amount' => $amount->value(),
            ],
            source: CommissionSourceReference::volumeEntry($entry),
        );
    }

    /**
     * The source entry, as a trace records it.
     *
     * @return array<string, string>
     */
    public static function source(VolumeEntry $entry): array
    {
        return [
            'volume_entry_id' => (string) $entry->getKey(),
            'volume_type' => $entry->type,
            'source_type' => $entry->source_type,
            'source_id' => $entry->source_id,
            'member_id' => $entry->member_id,
            'quantity' => $entry->quantity->value(),
            'effective_at' => $entry->effective_at->format('Y-m-d H:i:s'),
        ];
    }

    public static function key(VolumeEntry $entry, int $depth): string
    {
        return "volume-entry:{$entry->getKey()}:depth:{$depth}";
    }
}
