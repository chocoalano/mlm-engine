import { gridClass } from '@/panel/lib/grid';
import type { CardFaceDefinition, ColumnDefinition } from '@/panel/types/table';

/**
 * The card face, as column definitions rather than names.
 *
 * The server sends which column fills which slot; this turns those names into
 * the definitions the cell renderer needs, and applies the one rule that is
 * the user's rather than the schema's — column visibility.
 */
export interface ResolvedCardFace {
    image: ColumnDefinition | null;
    title: ColumnDefinition | null;
    description: ColumnDefinition | null;
    badges: ColumnDefinition[];
    details: ColumnDefinition[];
    /**
     * The grid classes for the run of cards.
     *
     * From `panel/lib/grid`, never interpolated: `grid-cols-${n}` is invisible
     * to the Tailwind compiler, so the class would not exist in the bundle and
     * the grid would silently collapse to one column.
     */
    gridClasses: string;
}

/**
 * Which slots a card actually draws, for this user.
 *
 * The visibility rule is split, and deliberately:
 *
 * - **`image` and `title` ignore it.** A card with no heading is not a card,
 *   and these are the columns a table normally marks `toggleable(false)`
 *   anyway. Hiding the identifying column should not leave a run of anonymous
 *   rectangles.
 * - **`description`, `badges` and `details` respect it.** They are the card's
 *   equivalent of a row's cells, and the column manager is exactly the control
 *   that answers "which of these do I want to see".
 */
export function resolveCardFace(
    cards: CardFaceDefinition,
    columns: ColumnDefinition[],
    visible: string[],
): ResolvedCardFace {
    const byName = new Map(columns.map((column) => [column.name, column]));
    const shown = new Set(visible);

    const find = (name: string | null): ColumnDefinition | null =>
        name === null ? null : (byName.get(name) ?? null);

    const findVisible = (name: string): ColumnDefinition | null =>
        shown.has(name) ? find(name) : null;

    const list = (names: string[]): ColumnDefinition[] =>
        names
            .map(findVisible)
            .filter((column): column is ColumnDefinition => column !== null);

    return {
        image: find(cards.image),
        title: find(cards.title),
        description:
            cards.description === null ? null : findVisible(cards.description),
        badges: list(cards.badges),
        details: list(cards.details),
        gridClasses: gridClass(cards.columns),
    };
}
