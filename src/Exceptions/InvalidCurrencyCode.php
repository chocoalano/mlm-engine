<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;

final class InvalidCurrencyCode extends DomainException
{
    public static function shape(string $code): self
    {
        return new self(sprintf('A currency code is three uppercase ASCII letters, such as "IDR"; "%s" given.', $code));
    }
}
