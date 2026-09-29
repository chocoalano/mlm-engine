<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;

final class UnknownCommissionStrategy extends DomainException
{
    /**
     * @param  list<string>  $registered
     */
    public static function forKey(string $key, array $registered): self
    {
        return new self(sprintf(
            'No commission strategy is registered under "%s". Registered: %s.',
            $key,
            $registered === [] ? 'none' : implode(', ', $registered),
        ));
    }
}
