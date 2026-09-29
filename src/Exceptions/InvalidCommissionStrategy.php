<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;
use PandaBear\Mlm\Commission\CommissionStrategy;

final class InvalidCommissionStrategy extends DomainException
{
    public static function key(string $key, CommissionStrategy $strategy): self
    {
        return new self(sprintf(
            '%s declares the commission strategy key "%s"; a key is 1–100 lowercase letters, digits, ".", "-" or "_", starting with a letter or digit.',
            $strategy::class,
            $key,
        ));
    }
}
