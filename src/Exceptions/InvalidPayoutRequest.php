<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;

/**
 * A payout request that cannot be recorded (ADR-030): an amount that is
 * not strictly positive or larger than one posting, a destination or key
 * spelled otherwise, or a member, wallet and settlement account that do
 * not belong together. Nothing is recorded.
 */
final class InvalidPayoutRequest extends DomainException
{
    public static function because(string $reason): self
    {
        return new self("A payout request cannot be recorded: {$reason}");
    }
}
