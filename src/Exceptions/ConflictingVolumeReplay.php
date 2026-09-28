<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;
use PandaBear\Mlm\Models\VolumeEntry;

final class ConflictingVolumeReplay extends DomainException
{
    /**
     * @param  list<string>  $fields  the material fields that differ
     */
    public static function forKey(VolumeEntry $existing, array $fields): self
    {
        return new self(sprintf(
            'Idempotency key "%s" in program [%s] already recorded volume entry [%s], which differs in %s. A replay must repeat the original request exactly.',
            $existing->idempotency_key,
            $existing->program_id,
            $existing->getKey(),
            implode(', ', $fields),
        ));
    }
}
