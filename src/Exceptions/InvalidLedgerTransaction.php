<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;
use PandaBear\Mlm\Finance\FinancialAmount;

/**
 * A ledger transaction that cannot be posted as requested: malformed input,
 * or postings that are not one balanced, single-currency movement within one
 * program. Nothing is written.
 */
final class InvalidLedgerTransaction extends DomainException
{
    public static function identifier(string $field, string $value): self
    {
        return new self(sprintf(
            'The ledger %s must be 1–64 lowercase letters, digits, ".", "-" or "_", starting with a letter or digit; "%s" given.',
            $field,
            $value,
        ));
    }

    public static function text(string $field, string $value, int $maxLength): self
    {
        return new self(sprintf(
            'The ledger %s must be 1–%d characters with no surrounding whitespace or control characters; "%s" given.',
            $field,
            $maxLength,
            $value,
        ));
    }

    public static function notAPosting(mixed $posting): self
    {
        return new self('Every posting must be a LedgerPostingInput; '.get_debug_type($posting).' given.');
    }

    public static function tooFewPostings(int $count): self
    {
        return new self("A ledger transaction moves value between at least two accounts; {$count} posting(s) given.");
    }

    public static function zeroPosting(): self
    {
        return new self('A posting of zero records nothing; every posting must be positive or negative.');
    }

    public static function tooLargeForPosting(FinancialAmount $amount, string $limit): self
    {
        return new self("One posting holds at most {$limit} either way, so that it can always be reversed; {$amount} is too large.");
    }

    public static function duplicateAccount(string $account): self
    {
        return new self("Ledger account [{$account}] appears in more than one posting; a transaction has one posting per account — combine them first.");
    }

    public static function unbalanced(FinancialAmount $sum): self
    {
        return new self("The postings of a ledger transaction must sum to exactly zero; they sum to {$sum}.");
    }

    public static function missingAccount(string $account): self
    {
        return new self("Ledger account [{$account}] does not exist.");
    }

    public static function otherProgram(string $account, string $accountProgram, string $program): self
    {
        return new self("Ledger account [{$account}] belongs to program [{$accountProgram}], not to the transaction's program [{$program}].");
    }

    public static function otherCurrency(string $account, string $accountCurrency, string $currency): self
    {
        return new self("Ledger account [{$account}] holds {$accountCurrency}, not the transaction's currency {$currency}; a transaction moves one currency.");
    }
}
