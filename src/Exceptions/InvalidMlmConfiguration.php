<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use InvalidArgumentException;

final class InvalidMlmConfiguration extends InvalidArgumentException
{
    public static function mustBe(string $key, string $expected, mixed $value): self
    {
        $given = is_scalar($value) || $value === null
            ? var_export($value, true)
            : get_debug_type($value);

        return new self("The [{$key}] configuration value must be {$expected}, {$given} given.");
    }
}
