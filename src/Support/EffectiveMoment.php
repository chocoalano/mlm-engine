<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * @internal
 *
 * How the package stores and compares a business moment — a volume entry's
 * `effective_at`, a genealogy edge's time, a path's `effective_from`: the
 * same instant in the application's timezone, to the second. One moment
 * given in two timezones is one moment, and nothing below a second counts,
 * as the columns keep seconds only.
 */
final class EffectiveMoment
{
    public static function of(DateTimeInterface $at): CarbonImmutable
    {
        return CarbonImmutable::instance($at)
            ->setTimezone(date_default_timezone_get())
            ->startOfSecond();
    }
}
