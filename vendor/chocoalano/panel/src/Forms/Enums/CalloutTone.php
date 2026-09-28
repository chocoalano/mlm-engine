<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Enums;

/**
 * How loudly a callout speaks.
 *
 * Closed, because each case maps to a literal set of Tailwind classes and to
 * an icon the build compiled in.
 */
enum CalloutTone: string
{
    case Info = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Danger = 'danger';

    /**
     * The icon this tone carries when a callout does not name its own.
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
}
