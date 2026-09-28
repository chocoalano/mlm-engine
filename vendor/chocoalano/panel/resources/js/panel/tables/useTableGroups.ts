import { computed, type ComputedRef } from 'vue';
import type { TableRow } from '@/panel/types/table';

export interface TableGroups {
    /** The index of the first row of each band. */
    breaks: ComputedRef<Set<number>>;
    /** The index of the last row of each band, keyed to that band. */
    ends: ComputedRef<Map<number, string>>;
}

/**
 * Where a table's group headings and per-band figures belong.
 *
 * Derived from the rows as they arrived rather than from a grouped structure,
 * because grouping is presentation — the query still returns a page, and a
 * band can be split across two pages exactly as any run of rows can.
 *
 * Index sets rather than markup, so a renderer that draws bands as heading
 * rows and one that draws them as full-width headings between card runs read
 * the same answer.
 */
export function useTableGroups(rows: () => TableRow[]): TableGroups {
    const breaks = computed(() => {
        const found = new Set<number>();

        let previous: string | null = null;

        rows().forEach((row, index) => {
            if (row.group !== null && row.group.key !== previous) {
                found.add(index);
                previous = row.group.key;
            }
        });

        return found;
    });

    /**
     * Figures go under the band they describe rather than above it, so a total
     * reads the way a column of numbers does.
     */
    const ends = computed(() => {
        const found = new Map<number, string>();
        const all = rows();

        all.forEach((row, index) => {
            const next = all[index + 1];

            if (
                row.group !== null &&
                (next === undefined || next.group?.key !== row.group.key)
            ) {
                found.set(index, row.group.key);
            }
        });

        return found;
    });

    return { breaks, ends };
}
