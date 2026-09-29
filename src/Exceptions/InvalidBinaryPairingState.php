<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;

/**
 * Stored binary pairing state that is not what the run read, or that no
 * supported write produces — another program's cursor, a lot reversed
 * twice. The run stops rather than overwrite or read it (ADR-023).
 */
final class InvalidBinaryPairingState extends DomainException
{
    public static function changed(string $component, string $what): self
    {
        return new self("The binary pairing state of component [{$component}] changed while the run was calculated: {$what}. Nothing was written; calculate again.");
    }

    public static function corrupt(string $component, string $what): self
    {
        return new self("The stored binary pairing state of component [{$component}] is not one a pairing run writes: {$what}.");
    }
}
