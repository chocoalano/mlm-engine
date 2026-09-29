<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;
use Throwable;

/**
 * A calculation that cannot run as requested: an invalid request, a
 * component that is not a validated commission component, or a source
 * account that cannot receive the run's postings. Nothing is written.
 */
final class InvalidCalculationRun extends DomainException
{
    public static function openRange(): self
    {
        return new self('A calculation covers a closed range [from, until): both bounds are required.');
    }

    public static function emptyRange(string $from, string $until): self
    {
        return new self("A calculation range must start before it ends; [{$from}, {$until}) is empty.");
    }

    public static function idempotencyKey(string $key): self
    {
        return new self(sprintf('A calculation idempotency key is 1–191 characters with no surrounding whitespace or control characters; "%s" given.', $key));
    }

    public static function insideTransaction(string $connection): self
    {
        return new self("A calculation runs in a read-snapshot transaction of its own, but connection [{$connection}] is already inside a transaction. Start the calculation outside any transaction.");
    }

    public static function missing(string $what, string $id): self
    {
        return new self("Cannot calculate: the {$what} [{$id}] does not exist.");
    }

    /**
     * @param  string  $where  e.g. "plan version [01…] (version 2), component "sales-commission""
     */
    public static function notACommissionComponent(string $where, string $driver): self
    {
        return new self(sprintf('Cannot calculate %s: its driver is "%s", not "commission.strategy".', $where, $driver));
    }

    public static function draft(string $where): self
    {
        return new self("Cannot calculate {$where}: its plan version is a draft. Only a validated version — validated, published, active, superseded or archived — can be calculated.");
    }

    public static function invalidDefinition(string $where, Throwable $previous): self
    {
        return new self("Cannot calculate {$where}: its stored definition is not valid: {$previous->getMessage()}", previous: $previous);
    }

    public static function unknownStrategy(string $where, string $strategy): self
    {
        return new self("Cannot calculate {$where}: no commission strategy is registered under \"{$strategy}\".");
    }

    public static function sourceAccount(string $where, string $key, string $currency, string $reason): self
    {
        return new self("Cannot calculate {$where}: its source account \"{$key}\" ({$currency}) {$reason}");
    }
}
