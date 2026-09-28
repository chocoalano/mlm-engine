import { router } from '@inertiajs/vue3';
import {
    clearFilterParam,
    markFiltersExplicit,
    writeFilterParam,
} from '@/panel/tables/filterParams';
import type { RelationDefinition } from '@/panel/types/relation';
import type { FilterValue, SortDirection } from '@/panel/types/table';

export type UseRelationTableReturn = {
    setSearch: (term: string) => void;
    setSort: (column: string) => void;
    setPage: (page: number) => void;
    setPerPage: (perPage: number) => void;
    setFilter: (name: string, value: FilterValue | null) => void;
    setFilters: (values: Record<string, FilterValue | null>) => void;
    setColumns: (visible: string[], order: string[]) => void;
    resetColumns: () => void;
    setColumnSearch: (name: string, term: string) => void;
    clearFilters: () => void;
    nextDirectionFor: (column: string) => SortDirection;
};

/**
 * Table state for one relation on a record page.
 *
 * The URL is the state here exactly as it is for a resource index, with one
 * difference: several relation tables share a page, so each writes under its
 * own namespace (`?relations[posts][page]=2`). Without that they would share
 * `?page=`, and paging one would page all of them.
 *
 * The visit stays on the current URL rather than navigating to an index —
 * the page belongs to the owner record, not to the relation.
 */
export function useRelationTable(
    relation: () => RelationDefinition,
): UseRelationTableReturn {
    /**
     * `relations.posts` + `sort` becomes `relations[posts][sort]`, which is
     * how PHP parses a nested query parameter back into the array the server
     * reads its state from.
     */
    function paramFor(key: string, suffix?: string): string {
        const segments = relation().stateKey.split('.');
        const [root, ...rest] = segments;
        const path = [...rest, key, ...(suffix === undefined ? [] : [suffix])];

        return `${root}${path.map((part) => `[${part}]`).join('')}`;
    }

    function visit(mutate: (params: URLSearchParams) => void): void {
        const params = new URLSearchParams(window.location.search);

        mutate(params);

        const query = params.toString();

        router.get(
            `${window.location.pathname}${query === '' ? '' : `?${query}`}`,
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
        value: string | number | null,
    ): void {
        if (value === null || value === '') {
            params.delete(key);

            return;
        }

        params.set(key, String(value));
    }

    /**
     * Any change other than paging returns this relation to page one, and
     * only this one: the other tables on the page keep where they were.
     */
    function resetPage(params: URLSearchParams): void {
        params.delete(paramFor('page'));
    }

    function nextDirectionFor(column: string): SortDirection {
        const { state } = relation();

        if (state.sort !== column) {
            return 'asc';
        }

        return state.direction === 'asc' ? 'desc' : 'asc';
    }

    return {
        nextDirectionFor,

        setSearch(term: string): void {
            visit((params) => {
                set(params, paramFor('search'), term.trim());
                resetPage(params);
            });
        },

        setSort(column: string): void {
            const direction = nextDirectionFor(column);

            visit((params) => {
                set(params, paramFor('sort'), column);
                set(params, paramFor('direction'), direction);
                resetPage(params);
            });
        },

        setPage(page: number): void {
            visit((params) =>
                set(params, paramFor('page'), page <= 1 ? null : page),
            );
        },

        setPerPage(perPage: number): void {
            visit((params) => {
                set(params, paramFor('perPage'), perPage);
                resetPage(params);
            });
        },

        setColumnSearch(name: string, term: string): void {
            visit((params) => {
                set(params, paramFor('columnSearch', name), term.trim());
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
                    writeFilterParam(params, paramFor('filters', name), value);
                }

                markFiltersExplicit(params, paramFor('filters'));
                resetPage(params);
            });
        },

        /**
         * Which columns are shown and in what order. Written as one visit:
         * they are one decision, and the server validates them together.
         */
        setColumns(visible: string[], order: string[]): void {
            visit((params) => {
                writeFilterParam(
                    params,
                    paramFor('columns', 'visible'),
                    visible,
                );
                writeFilterParam(params, paramFor('columns', 'order'), order);
            });
        },

        /** Back to whatever the table declared. */
        resetColumns(): void {
            visit((params) => {
                clearFilterParam(params, paramFor('columns', 'visible'));
                clearFilterParam(params, paramFor('columns', 'order'));
            });
        },

        setFilter(name: string, value: FilterValue | null): void {
            visit((params) => {
                writeFilterParam(params, paramFor('filters', name), value);
                // See `markFiltersExplicit`: deleting the last filter key
                // makes the request silent, and silence means "restore".
                markFiltersExplicit(params, paramFor('filters'));
                resetPage(params);
            });
        },

        clearFilters(): void {
            const prefixes = [paramFor('filters'), paramFor('columnSearch')];

            visit((params) => {
                for (const key of [...params.keys()]) {
                    if (prefixes.some((prefix) => key.startsWith(prefix))) {
                        params.delete(key);
                    }
                }

                // Said out loud rather than deleted: the server remembers
                // both, and a deleted key reads as "not talking about this",
                // which restores what was just cleared.
                markFiltersExplicit(params, paramFor('filters'));
                params.set(paramFor('search'), '');
                resetPage(params);
            });
        },
    };
}
