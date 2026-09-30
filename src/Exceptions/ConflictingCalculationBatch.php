<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;
use PandaBear\Mlm\Models\CalculationBatch;

/**
 * A hybrid calculation under an idempotency key a batch of the program
 * already holds, asking for something else: another plan version, range or
 * source account. A key names one request; a new request needs a new key.
 * Nothing is calculated.
 */
final class ConflictingCalculationBatch extends DomainException
{
    /**
     * @param  list<string>  $conflicts
     */
    public static function forKey(CalculationBatch $batch, array $conflicts): self
    {
        return new self("Calculation batch [{$batch->getKey()}] already holds idempotency key \"{$batch->idempotency_key}\" in program [{$batch->program_id}]; this request differs in ".implode(', ', $conflicts).'.');
    }
}
