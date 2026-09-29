<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;
use PandaBear\Mlm\Models\Commission;

/**
 * A posted or reversed commission whose stored ledger state does not match
 * it — only raw writes produce one — or a reversal replayed with another
 * moment.
 */
final class InvalidCommissionPosting extends DomainException
{
    public static function inconsistent(Commission $commission, string $reason): self
    {
        return new self("Commission [{$commission->getKey()}] is {$commission->status->value}, but {$reason}");
    }

    public static function reversedAtAnotherMoment(Commission $commission, string $stored, string $requested): self
    {
        return new self("Commission [{$commission->getKey()}] was already reversed at {$stored}; a reversal at {$requested} is another request, and a commission is reversed once.");
    }
}
