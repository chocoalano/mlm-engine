<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission\Strategies\Support;

use Illuminate\Database\Query\Builder;
use PandaBear\Mlm\Commission\CommissionCalculationContext;
use PandaBear\Mlm\Models\VolumeEntry;

/**
 * @internal
 *
 * The original volume entries a calculation may pay on: in the run's
 * program, of the filter's volume type and source type, effective in
 * [from, until), and not reversed before `until` — a reversal effective at
 * or after the cutoff belongs to a later range. Reversal entries are never
 * returned themselves.
 *
 * Read in id order, a chunk at a time, on the calculation's connection —
 * inside its snapshot. It knows nothing of sponsors or awards, and does not
 * judge the minimum quantity.
 */
final class EligibleVolumeEntries
{
    private const CHUNK = 500;

    /**
     * @return iterable<VolumeEntry> each with its member loaded
     */
    public static function of(CommissionCalculationContext $context, SourceEntryFilter $filter): iterable
    {
        $until = $context->until;

        return VolumeEntry::on($context->connection)
            ->where('program_id', $context->program->getKey())
            ->where('type', $filter->volumeType)
            ->where('source_type', $filter->sourceType)
            ->whereNull('reversal_of_id')
            ->where('effective_at', '>=', $context->from)
            ->where('effective_at', '<', $until)
            ->whereNotExists(static fn (Builder $reversals): Builder => $reversals
                ->selectRaw('1')
                ->from('mlm_volume_entries as reversals')
                ->whereColumn('reversals.reversal_of_id', 'mlm_volume_entries.id')
                ->where('reversals.effective_at', '<', $until))
            ->with('member')
            ->lazyById(self::CHUNK);
    }

    /**
     * The eligible entries whose quantity reaches the filter's minimum.
     *
     * @return iterable<VolumeEntry> each with its member loaded
     */
    public static function reachingMinimum(CommissionCalculationContext $context, SourceEntryFilter $filter): iterable
    {
        foreach (self::of($context, $filter) as $entry) {
            if ($filter->reachesMinimum($entry)) {
                yield $entry;
            }
        }
    }
}
