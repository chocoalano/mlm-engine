<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;

/**
 * A payout batch step that cannot be taken: the batch is not where the
 * step starts, a request cannot join it, or its requests are not ready.
 * Nothing changes.
 */
final class InvalidPayoutBatch extends DomainException
{
    public static function because(string $batch, string $reason): self
    {
        return new self("Payout batch [{$batch}]: {$reason}");
    }
}
