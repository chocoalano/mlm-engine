<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;
use PandaBear\Mlm\Models\CalculationRun;

final class ConflictingCalculationReplay extends DomainException
{
    /**
     * @param  list<string>  $fields  the material fields that differ
     */
    public static function forKey(CalculationRun $existing, array $fields): self
    {
        return new self(sprintf(
            'Idempotency key "%s" in program [%s] already recorded calculation run [%s], which differs in %s. A replay must repeat the original request exactly; a new calculation needs a new key.',
            $existing->idempotency_key,
            $existing->program_id,
            $existing->getKey(),
            implode(', ', $fields),
        ));
    }
}
