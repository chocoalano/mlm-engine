<?php

declare(strict_types=1);

namespace PandaPanel\Support;

/**
 * The column counts a container may be divided into.
 *
 * One place because eleven `columns()` setters answered this differently.
 * `Forms\Layouts\Grid` clamped; the other ten did not, so `columns(6)` was
 * serialized as six, the renderer had no literal Tailwind class for six, and
 * the fallback drew **one** column. A form declared as six columns wide came
 * out as a single stack, with nothing anywhere to say why — the widest
 * possible miss, reported as the narrowest possible result.
 *
 * Clamped rather than rejected. Six columns is a reasonable thing to ask for
 * and an unreasonable thing to render on any screen a panel is used on; four
 * is the honest answer, and it is much closer to what was meant than one.
 * What the renderer draws is now always what the schema says, which is the
 * property that was missing.
 */
final class ColumnCount
{
    /**
     * Four, because that is what `panel/lib/grid.ts` has literal classes for.
     * An interpolated `grid-cols-${n}` is invisible to the Tailwind compiler
     * and would not exist in the bundle at all.
     */
    public const MAX = 4;

    public static function clamp(int $columns): int
    {
        return min(max($columns, 1), self::MAX);
    }
}
