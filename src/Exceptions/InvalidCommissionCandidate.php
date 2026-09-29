<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;
use PandaBear\Mlm\Finance\FinancialAmount;

/**
 * A commission strategy produced a candidate the calculation cannot accept.
 * The whole calculation fails: no run and no commission is stored.
 */
final class InvalidCommissionCandidate extends DomainException
{
    public static function key(string $key): self
    {
        return new self(sprintf('A commission candidate key is 1–191 characters with no surrounding whitespace or control characters; "%s" given.', $key));
    }

    public static function notPositive(string $key, FinancialAmount $amount): self
    {
        return new self("Commission candidate \"{$key}\" has amount {$amount}; a commission is strictly positive. Corrections are ledger reversals, not negative commissions.");
    }

    public static function tooLarge(string $key, FinancialAmount $amount, string $limit): self
    {
        return new self("Commission candidate \"{$key}\" has amount {$amount}; one commission is posted as one ledger posting, which holds at most {$limit}.");
    }

    public static function trace(string $key, string $reason): self
    {
        return new self("Commission candidate \"{$key}\" has a trace that is not inert JSON data: {$reason}");
    }

    public static function notACandidate(mixed $value): self
    {
        return new self('A commission strategy yields CommissionCandidate objects only; '.get_debug_type($value).' given.');
    }

    public static function duplicateKey(string $key): self
    {
        return new self("Commission candidate key \"{$key}\" was produced more than once; every candidate of a run has its own key.");
    }

    public static function missingMember(string $key, string $member): self
    {
        return new self("Commission candidate \"{$key}\" names member [{$member}], which does not exist.");
    }

    public static function otherProgram(string $key, string $member, string $memberProgram, string $program): self
    {
        return new self("Commission candidate \"{$key}\" names member [{$member}] of program [{$memberProgram}], not of the run's program [{$program}].");
    }
}
