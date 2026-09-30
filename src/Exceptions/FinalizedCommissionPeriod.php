<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;

/**
 * A business entry whose own moment falls in a commission period whose
 * input is closed (ADR-029) — one whose calculation has begun, or that is
 * calculated, finalized or released. Its commissions were calculated
 * without it, so it is refused rather than left uncounted. Record it at a
 * moment of an open period, or outside every period. Nothing is recorded.
 */
final class FinalizedCommissionPeriod extends DomainException
{
    public static function inputClosed(string $period, string $status, string $at, string $from, string $until): self
    {
        return new self("A business entry at {$at} falls in commission period [{$period}] [{$from}, {$until}), which is {$status} and takes no new entry; its commissions were calculated without it.");
    }
}
