import { router } from '@inertiajs/vue3';
import {
    clearFilterParam,
    markFiltersExplicit,
    writeFilterParam,
} from '@/panel/tables/filterParams';
import type {
    FilterValue,
    ResourceMeta,
    SortDirection,
    TableLayout,
    TableState,
} from '@/panel/types/table';

type QueryValue = string | number | null | undefined;

export type UseResourceReturn = {
    setSearch: (term: string) => void;
    setSort: (column: string) => void;
    setPage: (page: number) => void;
    setPerPage: (perPage: number) => void;
    setFilter: (name: string, value: FilterValue | null) => void;
    setFilters: (values: Record<string, FilterValue | null>) => void;
    setColumns: (visible: string[], order: string[]) => void;
    resetColumns: () => void;
    setColumnSearch: (name: string, term: string) => void;
    setLayout: (layout: TableLayout) => void;
    setTab: (key: string) => void;
    clearFilters: () => void;
    nextDirectionFor: (column: string) => SortDirection;
};

function currentQuery(): URLSearchParams {
    return new URLSearchParams(window.location.search);
}

/**
 * Applies table state by navigating.
 *
 * Every control writes to the URL and lets the server answer, which is what
 * makes back, forward, refresh, and bookmark behave. Nothing here keeps a
 * local copy of rows, sort, or filters: the props are the state.
 */
export function useResource(
    resource: () => ResourceMeta,
    state: () => TableState,
): UseResourceReturn {
    function visit(mutate: (params: URLSearchParams) => void): void {
        const params = currentQuery();

        mutate(params);

        const query = params.toString();

        router.get(
            query === ''
                ? resource().indexUrl
                : `${resource().indexUrl}?${query}`,
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    }

    function set(
        params: URLSearchParams,
        key: string,
        value: QueryValue,
    ): void {
        if (value === null || value === undefined || value === '') {
            params.delete(key);

            return;
        }

        params.set(key, String(value));
    }

    /**
     * Any change other than paging returns to page one. Staying on page 7
     * after a search that yields two results shows an empty table.
     */
    function resetPage(params: URLSearchParams): void {
        params.delete('page');
    }

    function nextDirectionFor(column: string): SortDirection {
        const current = state();

        if (current.sort !== column) {
            return 'asc';
        }

        return current.direction === 'asc' ? 'desc' : 'asc';
    }

    return {
        nextDirectionFor,

        setSearch(term: string): void {
            visit((params) => {
                const trimmed = term.trim();

                // An empty search is written rather than deleted. The server
                // remembers the search term, and a deleted key reads as "this
                // request is not talking about search" — which restores the
                // term the user just cleared.
                if (trimmed === '') {
                    params.set('search', '');
                } else {
                    set(params, 'search', trimmed);
                }

                resetPage(params);
            });
        },

        setSort(column: string): void {
            const direction = nextDirectionFor(column);

            visit((params) => {
                set(params, 'sort', column);
                set(params, 'direction', direction);
                resetPage(params);
            });
        },

        /**
         * Which renderer draws the records.
         *
         * Written, never deleted — even for `table`. The server remembers the
         * layout, and a deleted key reads as "this request is not talking
         * about layout", which restores the grid the user just left. Same trap
         * `setSearch` documents.
         *
         * No `resetPage()`: the grid and the table show the same page of the
         * same query, so being thrown back to page one for changing how those
         * rows are drawn would be a bug rather than insurance.
         */
        setLayout(layout: TableLayout): void {
            visit((params) => params.set('layout', layout));
        },

        setTab(key: string): void {
            visit((params) => {
                set(params, 'tab', key);
                // A tab narrows the result set, so page 7 of the previous
                // one is rarely where the user wants to land.
                resetPage(params);
            });
        },

        setPage(page: number): void {
            visit((params) => set(params, 'page', page <= 1 ? null : page));
        },

        setPerPage(perPage: number): void {
            visit((params) => {
                set(params, 'perPage', perPage);
                resetPage(params);
            });
        },

        /**
         * A per-column term narrows what the table-wide search already
         * found, so it is its own parameter rather than being folded into
         * `search`.
         */
        setColumnSearch(name: string, term: string): void {
            visit((params) => {
                set(params, `columnSearch[${name}]`, term.trim());
                resetPage(params);
            });
        },

        /**
         * Several filters in one visit, for a table that defers: applying
         * them one at a time would be one request per filter, each racing
         * the last.
         */
        setFilters(values: Record<string, FilterValue | null>): void {
            visit((params) => {
                for (const [name, value] of Object.entries(values)) {
                    writeFilterParam(params, `filters[${name}]`, value);
                }

                markFiltersExplicit(params, 'filters');
                resetPage(params);
            });
        },

        /**
         * Which columns are shown and in what order. Written as one visit:
         * they are one decision, and the server validates them together.
         */
        setColumns(visible: string[], order: string[]): void {
            visit((params) => {
                writeFilterParam(params, 'columns[visible]', visible);
                writeFilterParam(params, 'columns[order]', order);
            });
        },

        /** Back to whatever the table declared. */
        resetColumns(): void {
            visit((params) => {
                clearFilterParam(params, 'columns[visible]');
                clearFilterParam(params, 'columns[order]');
            });
        },

        setFilter(name: string, value: FilterValue | null): void {
            visit((params) => {
                // A structured value occupies many keys, so the whole prefix
                // is cleared before the new one is written — otherwise
                // setting a shorter value leaves the previous tail behind.
                writeFilterParam(params, `filters[${name}]`, value);
                // Clearing the *last* filter leaves no `filters[…]` key at
                // all, which the server reads as silence and answers by
                // restoring what it remembered.
                markFiltersExplicit(params, 'filters');
                resetPage(params);
            });
        },

        clearFilters(): void {
            visit((params) => {
                for (const key of [...params.keys()]) {
                    if (
                        key.startsWith('filters[') ||
                        key.startsWith('columnSearch[')
                    ) {
                        params.delete(key);
                    }
                }

                // Both of these are remembered by the server, so both have to
                // be said out loud rather than deleted. This is the button
                // that most obviously did nothing before: it removed every
                // key, the request went quiet, and the session put all of it
                // back.
                markFiltersExplicit(params, 'filters');
                params.set('search', '');
                resetPage(params);
            });
        },
    };
}
