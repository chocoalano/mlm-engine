<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Enums;

/**
 * Where a row's actions sit.
 *
 * A closed set because each case maps to a literal position in the table's
 * markup — a free string would have to be interpolated into a class or a
 * slot name, and neither survives the build.
 */
enum RecordActionsPosition: string
{
    case AfterColumns = 'after_columns';
    case BeforeColumns = 'before_columns';
    /** Folded into the cell of the first column, for a very narrow table. */
    case AfterCells = 'after_cells';
}
