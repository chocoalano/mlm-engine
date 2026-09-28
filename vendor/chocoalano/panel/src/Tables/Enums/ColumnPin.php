<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Enums;

/**
 * Which edge a frozen column sticks to.
 *
 * An enum rather than a boolean, because "frozen" on its own is ambiguous the
 * moment a table is wide enough to want it: the identifying columns belong on
 * the left and the row's actions belong on the right, and a table that froze
 * both would otherwise need two different methods to say so.
 */
enum ColumnPin: string
{
    case Start = 'start';
    case End = 'end';
}
