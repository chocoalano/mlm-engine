<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Enums;

/**
 * The renderer a column maps to on the frontend.
 *
 * Backed by strings because the value crosses into Vue, where it is the
 * discriminant of the column definition union.
 */
enum ColumnType: string
{
    case Text = 'text';
    case Number = 'number';
    case Badge = 'badge';
    case Boolean = 'boolean';
    case Date = 'date';
    case DateTime = 'datetime';
    case Image = 'image';
    case Icon = 'icon';
    case Color = 'color';
    case Custom = 'custom';
    case Toggle = 'toggle';
    case Checkbox = 'checkbox';
    case TextInput = 'text_input';
    case Select = 'select';
}
