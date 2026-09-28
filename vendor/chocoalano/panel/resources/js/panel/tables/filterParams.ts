/**
 * Anything a table writes into the query string: a filter value, a list of
 * column names, a nested map. Broader than `FilterValue` because column
 * visibility and order travel the same way and are not filters.
 */
export type QueryParamValue =
    string | number | boolean | readonly unknown[] | object;

/**
 * Writes a structured value into the query string.
 *
 * A filter's value is no longer always a string: a date filter is a pair, a
 * form filter is its form's data, and a query builder is a list of rules.
 * They all have to survive a round trip through the URL, because the URL is
 * the table's state — back, forward, refresh, and bookmark all depend on it.
 *
 * The bracketed shape is what PHP parses back into nested arrays, which is
 * how the server reads them.
 */
export function writeFilterParam(
    params: URLSearchParams,
    prefix: string,
    value: QueryParamValue | null,
): void {
    clearFilterParam(params, prefix);

    if (value === null) {
        return;
    }

    write(params, prefix, value);
}

/**
 * Removes every key belonging to one filter.
 *
 * Prefix matching rather than an exact delete: a structured value occupies
 * many keys, and setting a shorter value would otherwise leave the tail of
 * the previous one behind.
 */
export function clearFilterParam(
    params: URLSearchParams,
    prefix: string,
): void {
    for (const key of [...params.keys()]) {
        if (key === prefix || key.startsWith(`${prefix}[`)) {
            params.delete(key);
        }
    }
}

function write(params: URLSearchParams, key: string, value: unknown): void {
    if (value === null || value === undefined || value === '') {
        return;
    }

    if (
        typeof value === 'string' ||
        typeof value === 'number' ||
        typeof value === 'boolean'
    ) {
        params.set(key, String(value));

        return;
    }

    if (Array.isArray(value)) {
        value.forEach((entry, index) =>
            write(params, `${key}[${index}]`, entry),
        );

        return;
    }

    if (typeof value === 'object') {
        for (const [name, entry] of Object.entries(value)) {
            write(params, `${key}[${name}]`, entry);
        }
    }
}

/**
 * Makes the query string say something about filters, even when the answer is
 * "none".
 *
 * The server's rule is that the request wins whenever it mentions a value —
 * including saying it is now empty — and that **absence is the only case that
 * falls back to what the session remembered**. That is the right rule, and it
 * puts an obligation on this side: clearing a filter by deleting its key makes
 * the request silent, and silence means "restore what I had", so the filter
 * comes straight back.
 *
 * A query string cannot hold an empty array, so `filters=` is how it spells
 * "filters, and there are none". The bare key and the bracketed ones are
 * mutually exclusive: PHP would not know what to do with both, so whichever
 * applies here removes the other.
 *
 * Call after any mutation of a filter map. It is idempotent.
 */
export function markFiltersExplicit(
    params: URLSearchParams,
    prefix: string,
): void {
    const hasAny = [...params.keys()].some((key) =>
        key.startsWith(`${prefix}[`),
    );

    if (hasAny) {
        params.delete(prefix);

        return;
    }

    params.set(prefix, '');
}
