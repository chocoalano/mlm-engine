<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;

/**
 * A binary correction request that cannot be answered truthfully: the entry
 * is not a stored reversal of its original, or the binary carry it reaches
 * is not in a state a pairing run writes (ADR-024). Refused rather than
 * reported as a misleading correction plan.
 */
final class InvalidBinaryCorrection extends DomainException
{
    public static function missing(string $what, string $id): self
    {
        return new self("Cannot analyse a binary correction: the {$what} [{$id}] does not exist.");
    }

    public static function notAReversal(string $entry): self
    {
        return new self("Volume entry [{$entry}] is an original entry, not a reversal; only a reversal has a binary correction to analyse.");
    }

    public static function inconsistentReversal(string $reversal, string $original, string $reason): self
    {
        return new self("Volume entry [{$reversal}] does not reverse entry [{$original}] as the volume history does: {$reason}.");
    }

    public static function corruptLot(string $lot, string $reason): self
    {
        return new self("Binary carry lot [{$lot}] is not in a state a pairing run writes: {$reason}. No correction can be planned from it.");
    }
}
