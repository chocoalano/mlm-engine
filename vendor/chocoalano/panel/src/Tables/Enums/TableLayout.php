<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Enums;

/**
 * How a table draws its records.
 *
 * Backed by strings because the value crosses into Vue, where it decides
 * which renderer the page mounts.
 *
 * Deliberately without a `fromRequest()` companion. `SortDirection` has one
 * because every direction is valid on every table, so an unrecognised value
 * can fall back to a case and be done with. A layout cannot: whether `grid`
 * is meaningful depends on whether *this* table declared a card face, which
 * is a question only the schema can answer. Resolution therefore lives in
 * `TableQuery::layout()`, checked against `TableSchema::availableLayouts()`
 * the same way `perPage()` is checked against the declared options.
 */
enum TableLayout: string
{
    case Table = 'table';
    case Grid = 'grid';
}
