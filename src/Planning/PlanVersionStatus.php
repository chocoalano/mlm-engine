<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Planning;

/**
 * Where a plan version is in its life. Stored as the lowercase value.
 *
 * The lifecycle is a single forward line — every status has exactly one
 * successor, and archived has none — so a version never skips a step and
 * never goes back.
 */
enum PlanVersionStatus: string
{
    case Draft = 'draft';
    case Validated = 'validated';
    case Published = 'published';
    case Active = 'active';
    case Superseded = 'superseded';
    case Archived = 'archived';

    public function canTransitionTo(self $status): bool
    {
        return $status === match ($this) {
            self::Draft => self::Validated,
            self::Validated => self::Published,
            self::Published => self::Active,
            self::Active => self::Superseded,
            self::Superseded => self::Archived,
            self::Archived => null,
        };
    }

    /**
     * Only a draft's definition may change. A validated version is locked
     * too: until edits can invalidate a validation, editing one would leave a
     * "validated" version that was never validated in its current form.
     */
    public function isMutable(): bool
    {
        return $this === self::Draft;
    }
}
