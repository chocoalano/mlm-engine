<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;
use PandaBear\Mlm\Planning\PlanComponentDriver;

final class InvalidPlanComponentDriver extends DomainException
{
    public static function key(string $key, PlanComponentDriver $driver): self
    {
        return new self(sprintf(
            '%s declares the plan component driver key "%s"; a key is 1–100 lowercase letters, digits, ".", "-" or "_", starting with a letter or digit.',
            $driver::class,
            $key,
        ));
    }
}
