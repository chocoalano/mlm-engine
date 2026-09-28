<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Enums;

/**
 * The control a filter renders as. Also the discriminant of the filter
 * definition union on the frontend.
 */
enum FilterType: string
{
    case Select = 'select';
    case Boolean = 'boolean';
    case Date = 'date';
    case Ternary = 'ternary';
    case Form = 'form';
    case QueryBuilder = 'query_builder';
}
