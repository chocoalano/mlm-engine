<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Enums;

/**
 * Where a column's contents sit.
 *
 * Logical rather than physical — `Start` and `End` rather than left and right
 * — so a right-to-left locale flips without every table being rewritten. A
 * closed set because the frontend maps each case to a literal Tailwind class;
 * an interpolated `text-${alignment}` would compile to nothing.
 */
enum Alignment: string
{
    case Start = 'start';
    case Center = 'center';
    case End = 'end';
    case Justify = 'justify';

    /**
     * Accepts the physical names too, so `->alignment('right')` keeps working
     * and means what it always meant.
     */
    public static function fromRequest(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        return match ($value) {
            'left' => self::Start,
            'right' => self::End,
            default => self::tryFrom($value),
        };
    }
}
