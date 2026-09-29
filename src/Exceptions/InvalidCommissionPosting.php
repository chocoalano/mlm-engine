<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;
use PandaBear\Mlm\Models\Commission;

/**
 * A posted or reversed commission whose stored ledger state does not match
 * it — only raw writes produce one — a reversal replayed with another
 * moment, or a posting or reversal that would move the wrong amount: an
 * approved commission whose adjustments leave nothing to post, or a whole
 * reversal of a commission already partly corrected through the ledger.
 */
final class InvalidCommissionPosting extends DomainException
{
    public static function inconsistent(Commission $commission, string $reason): self
    {
        return new self("Commission [{$commission->getKey()}] is {$commission->status->value}, but {$reason}");
    }

    public static function nothingToPost(Commission $commission): self
    {
        return new self("Commission [{$commission->getKey()}] is approved, but its adjustments leave it no amount to post; it should have been cancelled. Nothing was posted.");
    }

    public static function partlyAdjusted(Commission $commission): self
    {
        return new self("Commission [{$commission->getKey()}] was already partly corrected through the ledger; reversing its whole original posting would take back more than it still pays. Nothing was reversed.");
    }

    public static function reversedAtAnotherMoment(Commission $commission, string $stored, string $requested): self
    {
        return new self("Commission [{$commission->getKey()}] was already reversed at {$stored}; a reversal at {$requested} is another request, and a commission is reversed once.");
    }
}
