<?php

declare(strict_types=1);

namespace PandaPanel\Infolists\Enums;

/**
 * The renderer an entry maps to on the frontend.
 *
 * Backed by strings because the value crosses into Vue, where it is the
 * discriminant of the entry definition union. A new case here without a
 * branch there is a compile error, which is the point.
 */
enum EntryType: string
{
    case Text = 'text';
    case Badge = 'badge';
    case Boolean = 'boolean';
    case DateTime = 'datetime';
    case KeyValue = 'key-value';
    case Icon = 'icon';
    case Image = 'image';
    case Color = 'color';
    case Code = 'code';
    case Repeatable = 'repeatable';
    case Custom = 'custom';
}
