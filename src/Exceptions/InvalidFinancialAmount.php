<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;

final class InvalidFinancialAmount extends DomainException
{
    public static function of(mixed $value, string $reason): self
    {
        $given = is_string($value) ? "\"{$value}\"" : get_debug_type($value).(is_scalar($value) ? ' '.var_export($value, true) : '');

        return new self("{$given} is not a financial amount: {$reason}.");
    }
}
