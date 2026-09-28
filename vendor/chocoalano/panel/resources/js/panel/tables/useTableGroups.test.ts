import { ref } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import { useTableGroups } from '@/panel/tables/useTableGroups';
import type { TableRow } from '@/panel/types/table';

/**
 * Where a table's bands start and stop.
 *
 * Shared by both renderers — the row table draws a heading row and a footer
 * row, the card grid draws a full-width heading and a figure strip — so the
 * two can only place a band in the same place if they read the same answer.
 * These are index sets over the rows as they arrived, because grouping is
 * presentation: the query still returns a page, and a band can be split
 * across two pages exactly as any run of rows can.
 */

function row(key: number, group: string | null): TableRow {
    return {
        key,
        group:
            group === null
                ? null
                : { key: group, title: group, description: null },
        cells: {},
        cellMeta: {},
        actions: [],
    };
}

describe('useTableGroups', () => {
    it('marks the first row of each band', () => {
        const { breaks } = useTableGroups(() => [
            row(1, 'open'),
            row(2, 'open'),
            row(3, 'done'),
        ]);

        expect([...breaks.value]).toEqual([0, 2]);
    });

    it('marks the last row of each band', () => {
        const { ends } = useTableGroups(() => [
            row(1, 'open'),
            row(2, 'open'),
            row(3, 'done'),
        ]);

        expect([...ends.value]).toEqual([
            [1, 'open'],
            [2, 'done'],
        ]);
    });

    it('finds nothing in an ungrouped table', () => {
        const { breaks, ends } = useTableGroups(() => [
            row(1, null),
            row(2, null),
        ]);

        expect(breaks.value.size).toBe(0);
        expect(ends.value.size).toBe(0);
    });

    it('finds nothing in an empty table', () => {
        const { breaks, ends } = useTableGroups(() => []);

        expect(breaks.value.size).toBe(0);
        expect(ends.value.size).toBe(0);
    });

    it('treats a band split across the page boundary as one run', () => {
        // The page starts mid-band. There is one break — at the top — and one
        // end, because the run continues onto the next page.
        const { breaks, ends } = useTableGroups(() => [
            row(1, 'open'),
            row(2, 'open'),
        ]);

        expect([...breaks.value]).toEqual([0]);
        expect([...ends.value]).toEqual([[1, 'open']]);
    });

    it('reopens a band that appears twice', () => {
        // The server orders by the group column, so this should not happen —
        // but if it does, two runs is the honest reading rather than one.
        const { breaks, ends } = useTableGroups(() => [
            row(1, 'open'),
            row(2, 'done'),
            row(3, 'open'),
        ]);

        expect([...breaks.value]).toEqual([0, 1, 2]);
        expect([...ends.value]).toEqual([
            [0, 'open'],
            [1, 'done'],
            [2, 'open'],
        ]);
    });

    it('handles a single row', () => {
        const { breaks, ends } = useTableGroups(() => [row(1, 'open')]);

        expect([...breaks.value]).toEqual([0]);
        expect([...ends.value]).toEqual([[0, 'open']]);
    });

    it('recomputes when the rows change', () => {
        // A `ref`, because that is what the caller passes: `props.rows`. A
        // plain closure over a local would not invalidate the computed, and a
        // test written that way would pass while proving nothing.
        const rows = ref<TableRow[]>([row(1, 'open')]);
        const { breaks } = useTableGroups(() => rows.value);

        expect(breaks.value.size).toBe(1);

        rows.value = [row(1, 'open'), row(2, 'done')];

        expect([...breaks.value]).toEqual([0, 1]);
    });

    it('reads the rows once per computation rather than once per row', () => {
        // `ends` used to index into the getter inside the loop, which called
        // it again for every row. Cheap here and not cheap on a page of a
        // hundred.
        const rows = [row(1, 'open'), row(2, 'open'), row(3, 'done')];
        const getter = vi.fn(() => rows);

        const { ends } = useTableGroups(getter);

        void ends.value;

        expect(getter).toHaveBeenCalledTimes(1);
    });
});
