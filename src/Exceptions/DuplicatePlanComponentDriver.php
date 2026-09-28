<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;
use PandaBear\Mlm\Planning\PlanComponentDriver;

final class DuplicatePlanComponentDriver extends DomainException
{
    public static function forKey(string $key, PlanComponentDriver $registered, PlanComponentDriver $duplicate): self
    {
        return new self(sprintf(
            'The plan component driver key "%s" is already registered by %s; %s cannot claim it too. Keys are registered once — choose a namespaced key.',
            $key,
            $registered::class,
            $duplicate::class,
        ));
    }
}
