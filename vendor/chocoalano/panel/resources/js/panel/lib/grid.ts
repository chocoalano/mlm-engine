/**
 * How `columns()`, `columnSpan()` and `columnSpanFull()` become Tailwind
 * classes.
 *
 * One module because there were three — the form grid, the infolist node and
 * the infolist renderer each carried their own copy — and they had already
 * drifted: a four-column form dropped to two at `md` while a four-column
 * infolist stayed at four, so the same declaration laid out differently
 * depending on which of the two was drawing it.
 *
 * Every class is written out in full. An interpolated `md:grid-cols-${n}` is
 * invisible to the Tailwind compiler, so the class would simply not exist in
 * the bundle and the grid would silently be one column.
 */

/** The column counts there are literal classes for. */
export const MAX_COLUMNS = 4;

/**
 * The ladder a column count climbs as the viewport widens.
 *
 * Always one column on a phone: two 160px form controls side by side are two
 * controls nobody can use. Never more than two at `md` — that is 768px, and a
 * panel spends about 256px of it on the sidebar — so three and four columns
 * wait for `lg`, where there is room for them.
 *
 * This is also what makes a span safe. A grid that is two columns wide at
 * `md` cannot hold an item spanning three: `grid-column: span 3` against two
 * tracks creates a third implicit one and the row overflows its container
 * sideways. Clamping a span needs the column count *at each breakpoint*,
 * which is the table below.
 */
const GRID_CLASSES: Record<number, string> = {
    1: 'grid-cols-1',
    2: 'grid-cols-1 md:grid-cols-2',
    3: 'grid-cols-1 md:grid-cols-2 lg:grid-cols-3',
    4: 'grid-cols-1 md:grid-cols-2 lg:grid-cols-4',
};

/** What each declared count actually resolves to, per breakpoint. */
const EFFECTIVE_COLUMNS: Record<number, { md: number; lg: number }> = {
    1: { md: 1, lg: 1 },
    2: { md: 2, lg: 2 },
    3: { md: 2, lg: 3 },
    4: { md: 2, lg: 4 },
};

const MD_SPAN_CLASSES: Record<number, string> = {
    1: '',
    2: 'md:col-span-2',
    3: 'md:col-span-3',
    4: 'md:col-span-4',
};

const LG_SPAN_CLASSES: Record<number, string> = {
    1: 'lg:col-span-1',
    2: 'lg:col-span-2',
    3: 'lg:col-span-3',
    4: 'lg:col-span-4',
};

/**
 * A span of `'full'`, whatever the container is divided into.
 *
 * `col-span-full` is `grid-column: 1 / -1` — the whole row at every
 * breakpoint, with no arithmetic and nothing to keep in step with the column
 * count. Resolving `'full'` to a number instead would have to pick one, and
 * the number is different at `md` than at `lg`.
 */
const FULL_SPAN_CLASS = 'col-span-full';

function normalizeColumns(columns: number): number {
    return columns in GRID_CLASSES ? columns : 1;
}

/** The grid classes for a container divided into `columns`. */
export function gridClass(columns: number): string {
    return GRID_CLASSES[normalizeColumns(columns)];
}

/**
 * The span classes for an item asking for `span` of `columns`.
 *
 * A span wider than its container is clamped to it rather than overflowing,
 * separately at each breakpoint — so `columnSpan(3)` inside `columns(4)` is
 * two columns at `md`, where the grid is two wide, and three at `lg`.
 */
export function spanClass(span: number | 'full', columns: number): string {
    if (span === 'full') {
        return FULL_SPAN_CLASS;
    }

    const effective = EFFECTIVE_COLUMNS[normalizeColumns(columns)];

    const requested = Math.max(1, Math.floor(span));

    const md = Math.min(requested, effective.md);
    const lg = Math.min(requested, effective.lg);

    // One column on a phone, so the base class never changes. The wider
    // breakpoints are only named when they differ from the one before, which
    // keeps the class list short and readable in the DOM.
    return [
        'col-span-1',
        MD_SPAN_CLASSES[md],
        lg === md ? '' : LG_SPAN_CLASSES[lg],
    ]
        .filter((value) => value !== '')
        .join(' ');
}
