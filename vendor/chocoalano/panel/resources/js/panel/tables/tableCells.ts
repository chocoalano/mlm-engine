import {
    computed,
    defineAsyncComponent,
    type Component,
    type ComputedRef,
} from 'vue';
import { safeUrl } from '@/lib/utils';
import { resolveEmptyStateComponent } from '@/panel/tables/registryEmptyStates';
import type {
    ColumnAlignment,
    ColumnDefinition,
    TableDefinition,
    TableRow,
} from '@/panel/types/table';

/**
 * What every renderer of a table's cells needs, whatever shape it draws them
 * in. Shared so a row and a card cannot disagree about where a cell links to
 * or how a column is aligned.
 */

/**
 * Logical alignment maps to literal classes. `text-${alignment}` would have to
 * be interpolated, and an interpolated class does not exist in the bundle.
 */
export const ALIGNMENT_CLASSES: Record<ColumnAlignment, string> = {
    start: 'text-start',
    center: 'text-center',
    end: 'text-end',
    justify: 'text-justify',
};

/**
 * Where a cell links to, or null.
 *
 * Through `safeUrl`, which allowlists the scheme: a URL is built by a closure
 * on the server and a `javascript:` one reaching an `href` would be the
 * server handing the page an executable.
 */
export function cellUrl(
    row: TableRow,
    column: ColumnDefinition,
): string | null {
    return safeUrl(row.cellMeta[column.name]?.url);
}

/**
 * A table that draws its own empty state. Loaded on demand: most tables use
 * the ordinary one, and bundling every custom view would cost them all.
 */
export function useEmptyStateComponent(
    table: () => TableDefinition,
): ComputedRef<Component | null> {
    return computed(() => {
        const name = table().emptyState.component;

        if (name === null) {
            return null;
        }

        const loader = resolveEmptyStateComponent(name);

        return loader === null ? null : defineAsyncComponent(loader);
    });
}
