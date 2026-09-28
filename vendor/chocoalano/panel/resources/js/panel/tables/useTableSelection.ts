import {
    rowSelectionFeature,
    tableFeatures,
    useTable,
} from '@tanstack/vue-table';
import { computed, type ComputedRef } from 'vue';
import type { ColumnDefinition, TableRow } from '@/panel/types/table';

/**
 * TanStack owns the row model and the selection, and nothing else.
 *
 * Sorting, filtering, and pagination are server-side and URL-driven. Column
 * visibility and order joined them once the server started deciding and
 * remembering both: keeping TanStack's copy as well would be a second,
 * conflicting source of truth for the same question.
 *
 * Defined at module scope, as the library recommends, so the feature set is
 * not rebuilt per component instance.
 */
const features = tableFeatures({
    rowSelectionFeature,
});

export interface TableSelection {
    tableInstance: ReturnType<typeof useTable<typeof features, TableRow>>;
    selectedKeys: ComputedRef<Array<string | number>>;
    allSelected: ComputedRef<boolean>;
    toggleAll: (checked: boolean) => void;
    toggleRow: (key: string | number, checked: boolean) => void;
    isRowSelected: (key: string | number) => boolean;
    clearSelection: () => void;
}

/**
 * The selection model a table's rows are ticked through.
 *
 * Shared rather than reimplemented per renderer. Two selection models would
 * mean ticking cards and then running a bulk action over the row table's empty
 * set — the selection is what the bulk action bar reads, and there can only be
 * one answer to "what is selected".
 *
 * Every accessor speaks in record keys rather than row indices, so nothing
 * here assumes the rows are drawn in a line.
 */
export function useTableSelection(
    rows: () => TableRow[],
    columns: () => ColumnDefinition[],
    onChange: (keys: Array<string | number>) => void,
): TableSelection {
    const tableInstance = useTable({
        features,
        columns: computed(() =>
            columns().map((column) => ({
                id: column.name,
                header: column.label,
                accessorFn: (row: TableRow) => row.cells[column.name],
            })),
        ),
        data: computed(rows),
        getRowId: (row: TableRow) => String(row.key),
    });

    const selectedKeys = computed(() =>
        tableInstance.getSelectedRowModel().rows.map((row) => row.original.key),
    );

    const allSelected = computed(
        () => rows().length > 0 && selectedKeys.value.length === rows().length,
    );

    function toggleAll(checked: boolean): void {
        tableInstance.toggleAllRowsSelected(checked);
        onChange(selectedKeys.value);
    }

    function toggleRow(key: string | number, checked: boolean): void {
        tableInstance.getRow(String(key))?.toggleSelected(checked);
        onChange(selectedKeys.value);
    }

    function isRowSelected(key: string | number): boolean {
        return tableInstance.getRow(String(key))?.getIsSelected() ?? false;
    }

    function clearSelection(): void {
        tableInstance.toggleAllRowsSelected(false);
        onChange([]);
    }

    return {
        tableInstance,
        selectedKeys,
        allSelected,
        toggleAll,
        toggleRow,
        isRowSelected,
        clearSelection,
    };
}
