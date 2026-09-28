/**
 * The panel's one colour vocabulary, mirroring `PandaPanel\Tables\Enums\
 * BadgeColor`.
 *
 * Defined once and shared by tables, forms, and infolists: a status shown as
 * a badge in a table and as a toggle button in a form is the same colour,
 * because it is the same map rather than two that agree today.
 *
 * The classes are literal. An interpolated `bg-${color}-100` would compile to
 * nothing, which is why a closed enum exists on the server at all.
 */
export type BadgeColorName =
    'neutral' | 'success' | 'warning' | 'danger' | 'info';

export const BADGE_CLASSES: Record<BadgeColorName, string> = {
    neutral: 'bg-muted text-muted-foreground',
    success:
        'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    warning:
        'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    danger: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
    info: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
};

/**
 * Icon colours reuse the badge palette rather than inventing a second one, so
 * a status is the same colour whichever way it is shown.
 */
export const ICON_CLASSES: Record<BadgeColorName, string> = {
    neutral: 'text-muted-foreground',
    success: 'text-emerald-600 dark:text-emerald-400',
    warning: 'text-amber-600 dark:text-amber-400',
    danger: 'text-red-600 dark:text-red-400',
    info: 'text-sky-600 dark:text-sky-400',
};

/**
 * The border and background a selected control wears, for the places a badge
 * is not a badge but a pressed button.
 */
export const SELECTED_CLASSES: Record<BadgeColorName, string> = {
    neutral: 'border-foreground/40 bg-muted text-foreground',
    success:
        'border-emerald-500 bg-emerald-100 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200',
    warning:
        'border-amber-500 bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200',
    danger: 'border-red-500 bg-red-100 text-red-900 dark:bg-red-950 dark:text-red-200',
    info: 'border-sky-500 bg-sky-100 text-sky-900 dark:bg-sky-950 dark:text-sky-200',
};
