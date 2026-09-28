<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Planning\PlanVersionStatus;

final class InvalidPlanVersionTransition extends DomainException
{
    public static function notNextStep(PlanVersion $version, PlanVersionStatus $to): self
    {
        return new self(sprintf(
            'Plan version [%s] (version %d) is [%s] and cannot move to [%s].',
            $version->getKey(),
            $version->version,
            $version->status->value,
            $to->value,
        ));
    }

    public static function olderThanActive(PlanVersion $version, PlanVersion $active): self
    {
        return new self(sprintf(
            'Plan version [%s] (version %d) cannot replace active version %d [%s]: activation only moves forward.',
            $version->getKey(),
            $version->version,
            $active->version,
            $active->getKey(),
        ));
    }

    public static function changedConcurrently(PlanVersion $version, PlanVersionStatus $expected): self
    {
        return new self(sprintf(
            'Plan version [%s] is no longer [%s]; another process changed it.',
            $version->getKey(),
            $expected->value,
        ));
    }

    /**
     * @param  list<string>  $columns
     */
    public static function outsideLifecycle(PlanVersion $version, array $columns): self
    {
        return new self(sprintf(
            'Plan version [%s] cannot set [%s] directly; use %s.',
            $version->getKey() ?? 'new',
            implode(', ', $columns),
            'PandaBear\Mlm\Planning\PlanVersionLifecycle',
        ));
    }
}
