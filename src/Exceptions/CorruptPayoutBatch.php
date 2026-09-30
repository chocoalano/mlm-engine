<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;

/**
 * A stored payout batch that no supported write produces — a request of
 * another program or currency, positions out of order, or requests in a
 * state its own status rules out. Refused, never repaired.
 */
final class CorruptPayoutBatch extends DomainException
{
    public static function because(string $batch, string $reason): self
    {
        return new self("Payout batch [{$batch}] is inconsistent: {$reason}.");
    }
}
