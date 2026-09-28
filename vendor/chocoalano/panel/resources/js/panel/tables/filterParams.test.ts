import { describe, expect, it } from 'vitest';
import {
    clearFilterParam,
    markFiltersExplicit,
    writeFilterParam,
} from '@/panel/tables/filterParams';

/**
 * How a filter value becomes a query string.
 *
 * The server's rule is that the request wins whenever it mentions a value —
 * including saying it is now empty — and that **absence** falls back to what
 * the session remembered. That puts the whole burden of "I have cleared this"
 * on this module: get it wrong and a filter the user just removed comes
 * straight back on the next render, which is the bug these functions exist to
 * prevent and the one hardest to see in a diff.
 */

function params(search: string): URLSearchParams {
    return new URLSearchParams(search);
}

function readable(value: URLSearchParams): string {
    return decodeURIComponent(value.toString());
}

describe('writeFilterParam', () => {
    it('writes a scalar', () => {
        const query = params('');

        writeFilterParam(query, 'filters[status]', 'open');

        expect(readable(query)).toBe('filters[status]=open');
    });

    it('writes a nested object as the brackets PHP parses back', () => {
        const query = params('');

        writeFilterParam(query, 'filters[created]', {
            from: '2026-01-01',
            to: '2026-02-01',
        });

        expect(readable(query)).toBe(
            'filters[created][from]=2026-01-01&filters[created][to]=2026-02-01',
        );
    });

    it('writes a list by index', () => {
        const query = params('');

        writeFilterParam(query, 'filters[adv]', [
            { column: 'name', operator: 'contains' },
        ]);

        expect(readable(query)).toBe(
            'filters[adv][0][column]=name&filters[adv][0][operator]=contains',
        );
    });

    it('skips a null, an undefined and an empty string inside a structure', () => {
        const query = params('');

        writeFilterParam(query, 'filters[created]', {
            from: '',
            to: '2026-02-01',
        });

        // An empty bound is an absent bound, not a key that says nothing.
        expect(readable(query)).toBe('filters[created][to]=2026-02-01');
    });

    it('clears the whole previous value before writing a shorter one', () => {
        const query = params(
            'filters[adv][0][column]=name&filters[adv][1][column]=email',
        );

        writeFilterParam(query, 'filters[adv]', [{ column: 'team' }]);

        // Without the prefix clear, the tail of the previous value survives
        // and the server reads a rule the user removed.
        expect(readable(query)).toBe('filters[adv][0][column]=team');
    });

    it('removes the key entirely for a null value', () => {
        const query = params('filters[status]=open&page=2');

        writeFilterParam(query, 'filters[status]', null);

        expect(readable(query)).toBe('page=2');
    });
});

describe('clearFilterParam', () => {
    it('removes the exact key and everything under it', () => {
        const query = params(
            'filters[created][from]=a&filters[created][to]=b&filters[status]=open',
        );

        clearFilterParam(query, 'filters[created]');

        expect(readable(query)).toBe('filters[status]=open');
    });

    it('leaves a key that merely starts with the same characters', () => {
        const query = params('filters[status]=open&filters[statusCode]=200');

        clearFilterParam(query, 'filters[status]');

        // `filters[statusCode]` is a different filter, not a child of this one.
        expect(readable(query)).toBe('filters[statusCode]=200');
    });
});

describe('markFiltersExplicit', () => {
    it('writes the bare key when nothing is left', () => {
        const query = params('page=2');

        markFiltersExplicit(query, 'filters');

        // The only way a query string can spell "filters, and there are none".
        // Deleting instead would make the request silent, and silence restores
        // what the session remembered.
        expect(readable(query)).toBe('page=2&filters=');
    });

    it('removes the bare key once a real filter is set', () => {
        const query = params('filters=&filters[status]=open');

        markFiltersExplicit(query, 'filters');

        // PHP cannot parse both a scalar and an array under one name.
        expect(readable(query)).toBe('filters[status]=open');
    });

    it('is idempotent', () => {
        const query = params('');

        markFiltersExplicit(query, 'filters');
        markFiltersExplicit(query, 'filters');

        expect(readable(query)).toBe('filters=');
    });

    it('works under a relation namespace', () => {
        const query = params('');

        markFiltersExplicit(query, 'relations[posts][filters]');

        expect(readable(query)).toBe('relations[posts][filters]=');
    });

    it('does not mistake another table for this one', () => {
        const query = params('relations[comments][filters][status]=open');

        markFiltersExplicit(query, 'relations[posts][filters]');

        // Two relation tables on one page each own their slice, and clearing
        // one must not read the other's filters as its own.
        expect(readable(query)).toBe(
            'relations[comments][filters][status]=open&relations[posts][filters]=',
        );
    });
});

describe('the sequence a chip close produces', () => {
    it('leaves the marker when the last filter goes', () => {
        const query = params('filters[status]=open&page=3');

        writeFilterParam(query, 'filters[status]', null);
        markFiltersExplicit(query, 'filters');

        expect(readable(query)).toBe('page=3&filters=');
    });

    it('leaves the others alone when one of several goes', () => {
        const query = params('filters[status]=open&filters[role]=admin');

        writeFilterParam(query, 'filters[status]', null);
        markFiltersExplicit(query, 'filters');

        expect(readable(query)).toBe('filters[role]=admin');
    });

    it('recovers from a previous clear', () => {
        const query = params('filters=');

        writeFilterParam(query, 'filters[status]', 'open');
        markFiltersExplicit(query, 'filters');

        expect(readable(query)).toBe('filters[status]=open');
    });
});

/*
 * The clear marker, as the server now reads it
 *
 * A query string cannot spell an empty array, so `filters=` is how the
 * frontend says "filters, and there are none". `ConvertEmptyStringsToNull`
 * then rewrites the value to null — but leaves the key, which is what the
 * server reads to tell an explicit clear from silence.
 *
 * These pin the half of that contract this side owns: the key must be
 * written, and it must not be written when real filters are present.
 */
describe('the marker a clear leaves behind', () => {
    it('sends an explicit clear marker instead of relying on an empty string', () => {
        const params = new URLSearchParams('filters[status]=active');

        clearFilterParam(params, 'filters[status]');
        markFiltersExplicit(params, 'filters');

        // The bare key, present and empty. Its presence is the whole signal;
        // its value is nulled in flight and never read.
        expect(params.has('filters')).toBe(true);
        expect(params.get('filters')).toBe('');
        expect(params.has('filters[status]')).toBe(false);
    });

    it('leaves the marker off while any filter is still set', () => {
        const params = new URLSearchParams(
            'filters[status]=active&filters[kind]=a',
        );

        clearFilterParam(params, 'filters[status]');
        markFiltersExplicit(params, 'filters');

        // One filter going away is not a clear-all: the remaining one is
        // still sent, and the bare key would contradict it.
        expect(params.has('filters')).toBe(false);
        expect(params.get('filters[kind]')).toBe('a');
    });

    it('survives being applied twice', () => {
        const params = new URLSearchParams();

        markFiltersExplicit(params, 'filters');
        markFiltersExplicit(params, 'filters');

        expect(params.getAll('filters')).toEqual(['']);
    });
});
