<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;

/**
 * A commission period step that cannot be taken now (ADR-029): the period
 * is not where the step starts, its commissions are not all reviewed, a
 * binary correction of one is not yet processed, or its release moment has
 * not come. Nothing changes.
 */
final class InvalidCommissionPeriodTransition extends DomainException
{
    public static function status(string $period, string $status, string $step, string $required): self
    {
        return new self("Commission period [{$period}] is {$status}; it is {$step} only when {$required}.");
    }

    /**
     * @param  array<string, int>  $counts  by status
     */
    public static function unreviewed(string $period, array $counts): self
    {
        $shown = implode(', ', array_map(static fn (string $status, int $count): string => "{$count} {$status}", array_keys($counts), $counts));

        return new self("Commission period [{$period}] cannot be finalized: every commission must be approved or cancelled, but it has {$shown}.");
    }

    /**
     * @param  list<string>  $commissions
     */
    public static function unresolvedBinaryCorrections(string $period, array $commissions): self
    {
        return new self("Commission period [{$period}] cannot be finalized: binary corrections of commissions [".implode(', ', $commissions).'] have no financial adjustment yet. Call CommissionAdjustmentEngine::processBinaryReversal() first.');
    }

    public static function early(string $period, string $at, string $release): self
    {
        return new self("Commission period [{$period}] is released no earlier than {$release}; {$at} is too early.");
    }
}
