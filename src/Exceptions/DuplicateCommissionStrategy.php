<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;
use PandaBear\Mlm\Commission\CommissionStrategy;

final class DuplicateCommissionStrategy extends DomainException
{
    public static function forKey(string $key, CommissionStrategy $registered, CommissionStrategy $duplicate): self
    {
        return new self(sprintf(
            'The commission strategy key "%s" is already registered by %s; %s cannot claim it too. Keys are registered once — choose a namespaced key.',
            $key,
            $registered::class,
            $duplicate::class,
        ));
    }
}
