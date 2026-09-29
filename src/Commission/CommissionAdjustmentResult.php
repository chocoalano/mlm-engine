<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission;

use PandaBear\Mlm\Models\CommissionAdjustment;

/**
 * The corrections one source reversal has led to so far: one adjustment per
 * commission linked to its original entry, in commission id order —
 * including those an earlier call made. A later call may find more, if a
 * calculation has since found commissions for that entry.
 */
final readonly class CommissionAdjustmentResult
{
    /**
     * @param  list<CommissionAdjustment>  $adjustments  in commission id order
     */
    public function __construct(
        public string $originalVolumeEntryId,
        public string $reversalVolumeEntryId,
        public array $adjustments,
    ) {}

    public function count(?CommissionAdjustmentOutcome $outcome = null): int
    {
        return count($outcome === null
            ? $this->adjustments
            : array_filter($this->adjustments, static fn (CommissionAdjustment $adjustment): bool => $adjustment->outcome === $outcome));
    }
}
