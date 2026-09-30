<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;

/**
 * A commission period that cannot be created or calculated (ADR-029): its
 * range or release moment is not a valid one, its plan version is not the
 * program's active one, its source account does not fund every commission
 * component, it overlaps another period of the program, or its version has
 * no commission component. Nothing is created or calculated.
 */
final class InvalidCommissionPeriod extends DomainException
{
    public static function range(string $from, string $until): self
    {
        return new self("A commission period runs from before it ends: [{$from}, {$until}) is empty or reversed.");
    }

    public static function releaseBeforeEnd(string $release, string $until): self
    {
        return new self("A commission period is released no earlier than it ends: release at {$release} is before {$until}.");
    }

    public static function inactiveVersion(string $version, string $status): self
    {
        return new self("Plan version [{$version}] is {$status}; a commission period is created for the program's active plan version.");
    }

    public static function idempotencyKey(string $key): self
    {
        return new self('A commission period idempotency key is 1 to 191 printable characters; '.var_export($key, true).' given.');
    }

    public static function otherProgram(string $what, string $id, string $program): self
    {
        return new self(ucfirst($what)." [{$id}] does not belong to program [{$program}].");
    }

    public static function funding(string $account, string $reason): self
    {
        return new self("Ledger account [{$account}] cannot fund this commission period: {$reason}");
    }

    public static function overlaps(string $program, string $from, string $until, string $other): self
    {
        return new self("Program [{$program}] already has commission period [{$other}] overlapping [{$from}, {$until}); a program's periods never overlap.");
    }

    public static function noCommissionComponents(string $period, string $version): self
    {
        return new self("Commission period [{$period}] has nothing to calculate: plan version [{$version}] has no commission component.");
    }

    public static function insideTransaction(string $connection): self
    {
        return new self("A commission period is calculated component by component, each in a transaction of its own, so it does not start inside one already open on connection [{$connection}]. Nothing was calculated.");
    }
}
