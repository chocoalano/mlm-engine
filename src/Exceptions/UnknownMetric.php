<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;

final class UnknownMetric extends DomainException
{
    /**
     * @param  list<string>  $registered
     */
    public static function forKey(string $key, array $registered): self
    {
        return new self(sprintf(
            'No metric is registered under "%s". Registered: %s.',
            $key,
            $registered === [] ? 'none' : implode(', ', $registered),
        ));
    }
}
