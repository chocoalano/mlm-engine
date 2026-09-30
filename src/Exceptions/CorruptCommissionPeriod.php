<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;

/**
 * A stored commission period that no supported write produces — its plan
 * version or source account in another program, a run that is not its
 * component's run over its range, a missing or extra component run, or
 * commissions in a state its own status rules out. Refused rather than
 * used, and never repaired: correct the rows.
 */
final class CorruptCommissionPeriod extends DomainException
{
    public static function because(string $period, string $reason): self
    {
        return new self("Commission period [{$period}] is inconsistent: {$reason}.");
    }
}
