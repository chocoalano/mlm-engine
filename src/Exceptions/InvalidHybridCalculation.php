<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;

/**
 * A hybrid calculation that cannot be made (ADR-028): the plan version has
 * fewer than two commission components, is a draft, or its components are
 * not all funded from the source account asked for, or the caller holds a
 * transaction open. Nothing is created or calculated.
 */
final class InvalidHybridCalculation extends DomainException
{
    public static function tooFewComponents(string $version, int $count): self
    {
        return new self($count === 1
            ? "Plan version [{$version}] has one commission component; calculate it with PandaBear\Mlm\Calculation\CalculationEngine. A hybrid calculation composes two or more."
            : "Plan version [{$version}] has no commission component; a hybrid calculation composes two or more.");
    }

    public static function draft(string $version): self
    {
        return new self("Plan version [{$version}] is a draft; only a validated version is calculated.");
    }

    public static function sourceAccount(string $account, string $reason): self
    {
        return new self("Ledger account [{$account}] cannot fund this hybrid calculation: {$reason}");
    }

    public static function insideTransaction(string $connection): self
    {
        return new self("A hybrid calculation runs each component in a transaction of its own, so it does not start inside one already open on connection [{$connection}]. Nothing was calculated.");
    }
}
