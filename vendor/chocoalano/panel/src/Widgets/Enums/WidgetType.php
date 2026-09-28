<?php

declare(strict_types=1);

namespace PandaPanel\Widgets\Enums;

/**
 * The renderer a widget maps to, and the discriminant of the widget
 * definition union on the frontend.
 */
enum WidgetType: string
{
    case Stats = 'stats';
    case Table = 'table';
    case Chart = 'chart';
    case Custom = 'custom';
}
