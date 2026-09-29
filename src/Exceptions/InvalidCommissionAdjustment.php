<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;

/**
 * A correction that cannot be made: the request is not a volume reversal,
 * or the stored reversal, its original, the commissions linked to it, their
 * binary correction journal or their adjustments do not agree — which only
 * raw writes or a strategy naming another program's entry produce. Nothing
 * is corrected.
 */
final class InvalidCommissionAdjustment extends DomainException
{
    public static function missing(string $what, string $id): self
    {
        return new self("Cannot adjust commissions: the {$what} [{$id}] does not exist.");
    }

    public static function notAReversal(string $entry): self
    {
        return new self("Volume entry [{$entry}] is an original entry, not a reversal; only a reversal claws commissions back.");
    }

    public static function inconsistentReversal(string $reversal, string $original, string $reason): self
    {
        return new self("Volume entry [{$reversal}] does not reverse entry [{$original}] as the volume history does: {$reason}. No commission was adjusted.");
    }

    public static function otherProgram(string $commission, string $commissionProgram, string $entry, string $entryProgram): self
    {
        return new self("Commission [{$commission}] of program [{$commissionProgram}] names volume entry [{$entry}] of program [{$entryProgram}] as its source; provenance never crosses programs. No commission was adjusted.");
    }

    public static function binaryJournal(string $reversal, string $reason): self
    {
        return new self("The binary corrections of volume reversal [{$reversal}] cannot be corrected financially: {$reason}. No commission was adjusted.");
    }

    public static function netOutOfRange(string $commission, string $amount, string $net): self
    {
        return new self("Commission [{$commission}] of {$amount} has adjustments leaving it {$net}; its net amount must lie between 0 and its calculated amount.");
    }
}
