<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;
use PandaBear\Mlm\Models\LedgerTransaction;

final class InvalidLedgerReversal extends DomainException
{
    public static function ofAReversal(LedgerTransaction $transaction): self
    {
        return new self("Ledger transaction [{$transaction->getKey()}] is itself a reversal, of transaction [{$transaction->reversal_of_id}], and cannot be reversed.");
    }

    public static function alreadyReversed(LedgerTransaction $original, string $reversalId): self
    {
        return new self("Ledger transaction [{$original->getKey()}] was already reversed by transaction [{$reversalId}]; a transaction is reversed once.");
    }
}
