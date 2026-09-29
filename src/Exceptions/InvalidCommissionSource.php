<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;

final class InvalidCommissionSource extends DomainException
{
    public static function type(string $type): self
    {
        return new self(sprintf('A commission source type is 1–64 lowercase letters, digits, ".", "-" or "_", starting with a letter or digit; "%s" given.', $type));
    }

    public static function id(string $id): self
    {
        return new self(sprintf('A commission source id is 1–191 characters with no surrounding whitespace or control characters; "%s" given.', $id));
    }
}
