<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;
use PandaBear\Mlm\Models\Commission;

/**
 * A commission of a commission period (ADR-029) is posted only once the
 * period is released and the commission is available — never straight
 * from approved or held. Nothing is posted.
 */
final class CommissionPeriodNotReleased extends DomainException
{
    public static function beforePosting(Commission $commission, string $period, string $periodStatus): self
    {
        return new self("Commission [{$commission->getKey()}] of commission period [{$period}] is {$commission->status->value} and the period is {$periodStatus}; a period's commission is posted only when it is available and its period released.");
    }
}
