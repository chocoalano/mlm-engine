<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;
use PandaBear\Mlm\Models\PlanVersion;

final class PlanVersionNotMutable extends DomainException
{
    public static function locked(PlanVersion $version): self
    {
        return new self(sprintf(
            'Plan version [%s] (version %d) is [%s] and locked; only a draft can change.',
            $version->getKey(),
            $version->version,
            $version->status->value,
        ));
    }
}
