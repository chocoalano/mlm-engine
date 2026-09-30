<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;

/**
 * A program, member or plan refused by `ProgramManager` because its code or
 * identity is already taken, or a required fact is blank. Nothing is
 * created.
 */
final class ConflictingProgramRecord extends DomainException
{
    public static function programCode(string $code): self
    {
        return new self("A program with code \"{$code}\" already exists.");
    }

    public static function memberCode(string $programId, string $code): self
    {
        return new self("Program [{$programId}] already has a member with code \"{$code}\".");
    }

    public static function externalIdentity(string $programId, string $type, string $id): self
    {
        return new self("Program [{$programId}] already has a member with external identity {$type}:{$id}.");
    }

    public static function planCode(string $programId, string $code): self
    {
        return new self("Program [{$programId}] already has a plan with code \"{$code}\".");
    }

    public static function blank(string $what, int $maxLength): self
    {
        return new self("The {$what} is required: 1–{$maxLength} characters, not blank.");
    }
}
