<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Enums;

/**
 * Semantic badge colours.
 *
 * A closed set rather than free-form strings, because the frontend maps each
 * case to a literal Tailwind class. An arbitrary colour name would compile to
 * nothing.
 */
enum BadgeColor: string
{
    case Neutral = 'neutral';
    case Success = 'success';
    case Warning = 'warning';
    case Danger = 'danger';
    case Info = 'info';

    public static function fromValue(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return is_string($value)
            ? (self::tryFrom($value) ?? self::Neutral)
            : self::Neutral;
    }
}
