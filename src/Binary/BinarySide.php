<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Binary;

/**
 * Where a binary child sits under its parent (ADR-022): exactly `left` or
 * `right`. A side is assigned explicitly — never derived from the order
 * members were placed in — and never changes.
 */
enum BinarySide: string
{
    case Left = 'left';
    case Right = 'right';

    /**
     * The side spelled exactly so, or null: nothing is trimmed, lowercased,
     * abbreviated or cast.
     */
    public static function parse(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }
}
