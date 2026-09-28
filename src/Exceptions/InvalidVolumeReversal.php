<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;
use PandaBear\Mlm\Models\VolumeEntry;

final class InvalidVolumeReversal extends DomainException
{
    public static function ofAReversal(VolumeEntry $entry): self
    {
        return new self("Volume entry [{$entry->getKey()}] is itself a reversal, of entry [{$entry->reversal_of_id}], and cannot be reversed.");
    }

    public static function alreadyReversed(VolumeEntry $original, string $reversalId): self
    {
        return new self("Volume entry [{$original->getKey()}] was already reversed by entry [{$reversalId}]; an entry is reversed once.");
    }
}
