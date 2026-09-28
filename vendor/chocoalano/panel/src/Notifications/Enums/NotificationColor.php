<?php

declare(strict_types=1);

namespace PandaPanel\Notifications\Enums;

/**
 * How loudly a notification speaks.
 *
 * The same four the rest of the panel uses, and closed for the same reason:
 * each maps to a literal set of classes on the frontend, and an interpolated
 * colour would compile to nothing.
 */
enum NotificationColor: string
{
    case Info = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Danger = 'danger';

    /**
     * The icon this colour carries when a notification does not name its own.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Info => 'info',
            self::Success => 'check',
            self::Warning => 'triangle-alert',
            self::Danger => 'circle-alert',
        };
    }

    /**
     * The toast channel this maps onto, so a transient notification and a
     * flash message look the same.
     *
     * @return 'success'|'info'|'warning'|'error'
     */
    public function toastType(): string
    {
        return match ($this) {
            self::Info => 'info',
            self::Success => 'success',
            self::Warning => 'warning',
            self::Danger => 'error',
        };
    }
}
