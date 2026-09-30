<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;
use PandaBear\Mlm\Models\PayoutBatch;

/**
 * A payout batch requested under an idempotency key a batch of the program
 * already holds, in another currency. Nothing is created.
 */
final class ConflictingPayoutBatch extends DomainException
{
    public static function forKey(PayoutBatch $batch, string $currency): self
    {
        return new self("Payout batch [{$batch->getKey()}] already holds idempotency key \"{$batch->idempotency_key}\" in program [{$batch->program_id}] for {$batch->currency}, not {$currency}.");
    }
}
