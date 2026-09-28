<script setup lang="ts">
import { ArrowDown, ArrowUp, ChevronsUpDown } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, nextTick, ref, watch } from 'vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import ActionButton from '@/panel/actions/ActionButton.vue';
import ActionGroup from '@/panel/actions/ActionGroup.vue';
import RowReorderControl from '@/panel/tables/RowReorderControl.vue';
import EmptyState from '@/panel/components/EmptyState.vue';
import type { CellEditValue } from '@/panel/composables/useActions';
import { usePanelStyling } from '@/panel/composables/usePanelStyling';
import DataTableCell from '@/panel/tables/DataTableCell.vue';
import {
    ALIGNMENT_CLASSES,
    cellUrl,
    useEmptyStateComponent,
} from '@/panel/tables/tableCells';
import { useFrozenColumns } from '@/panel/tables/useFrozenColumns';
import type { FrozenColumn } from '@/panel/tables/useFrozenColumns';
import { useTableGroups } from '@/panel/tables/useTableGroups';
import { useTableSelection } from '@/panel/tables/useTableSelection';
import type { ActionDefinition } from '@/panel/types/action';
import type {
    ColumnDefinition,
    TableDefinition,
    TableRow as TableRowData,
    TableGroupSummaries,
    TableState,
    TableSummaries,
} from '@/panel/types/table';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

const props = withDefaults(
    defineProps<{
        table: TableDefinition;
        rows: TableRowData[];
        state: TableState;
        /** Figures under the table; empty for one that declares none. */
        summaries?: TableSummaries;
        /** The same, per band, when the table is grouped. */
        groupSummaries?: TableGroupSummaries;
        /**
         * Whether the table draws its own frame.
         *
         * True standalone — a relation table on a record page, a table widget
         * — where it is one object among several and needs an edge of its
         * own. False on a resource index, where the toolbar, the rows and the
         * pagination are joined into a single surface and a second border
         * inside it would draw a box around the middle third of one object.
         */
        bordered?: boolean;
    }>(),
    { summaries: () => ({}), groupSummaries: () => ({}), bordered: true },
);

const emit = defineEmits<{
    columnSearch: [name: string, term: string];
    runTableAction: [action: ActionDefinition];
    editCell: [record: string | number, column: string, value: CellEditValue];
    sort: [column: string];
    selectionChange: [keys: Array<string | number>];
    runAction: [action: ActionDefinition, record: string | number];
    reorder: [keys: Array<string | number>];
}>();

const dragging = ref<string | number | null>(null);

/**
 * Reordering is a drag between two rows, so the resulting order is worked
 * out here and only the key order is sent. What a position means belongs to
 * the server; the client never invents a value for a column it knows nothing
 * about.
 */
function onDrop(targetKey: string | number): void {
    const from = dragging.value;

    dragging.value = null;

    if (from === null || from === targetKey) {
        return;
    }

    const keys = props.rows.map((row) => row.key);
    const fromIndex = keys.indexOf(from);
    const toIndex = keys.indexOf(targetKey);

    if (fromIndex === -1 || toIndex === -1) {
        return;
    }

    keys.splice(toIndex, 0, ...keys.splice(fromIndex, 1));

    emit('reorder', keys);
}

const {
    tableInstance,
    allSelected,
    toggleAll,
    toggleRow,
    isRowSelected,
    clearSelection,
} = useTableSelection(
    () => props.rows,
    () => props.table.columns,
    (keys) => emit('selectionChange', keys),
);

const definitionsByName = computed(
    () =>
        new Map<string, ColumnDefinition>(
            props.table.columns.map((column) => [column.name, column]),
        ),
);

const emptyStateComponent = useEmptyStateComponent(() => props.table);

const hasSummaries = computed(() => Object.keys(props.summaries).length > 0);

const { breaks: groupBreaks, ends: groupEnds } = useTableGroups(
    () => props.rows,
);

const actionsPosition = computed(() => props.table.recordActions.position);

const columnCount = computed(
    () =>
        visibleColumns.value.length +
        (props.table.selectable ? 1 : 0) +
        (props.table.reorderable ? 1 : 0) +
        (hasActionsColumn.value ? 1 : 0),
);

/** The tallest stack of figures under any one column. */
const summaryRowCount = computed(() =>
    Math.max(
        0,
        ...Object.values(props.summaries).map((figures) => figures.length),
    ),
);

const hasColumnSearch = computed(() =>
    props.table.columns.some((column) => column.individuallySearchable),
);

/**
 * Local, for the same reason the toolbar's box is: the server value only
 * arrives after the debounced visit, and binding straight to it would move
 * the caret while typing. Re-synced whenever the server value changes, so
 * back and forward still update the boxes.
 */
const columnTerms = ref<Record<string, string>>({
    ...props.state.columnSearches,
});

const emitColumnSearch = useDebounceFn(
    (name: string, term: string) => emit('columnSearch', name, term),
    computed(() => props.table.searchDebounce),
);

watch(
    () => props.state.columnSearches,
    (value) => {
        emitColumnSearch.cancel();
        columnTerms.value = { ...value };
    },
);

function onColumnSearch(name: string, term: string): void {
    columnTerms.value = { ...columnTerms.value, [name]: term };

    emitColumnSearch(name, term);
}

function clearColumnSearches(): void {
    emitColumnSearch.cancel();
    columnTerms.value = {};
}

/**
 * Read straight from the state the server applied, in the order it applied.
 *
 * Not from a local model: the server already dropped unknown names and kept
 * every non-toggleable column visible, so deriving it again here would be a
 * second place for the two to disagree.
 */
const orderedColumns = computed(() =>
    props.state.columns.visible
        .map((name) => definitionsByName.value.get(name))
        .filter((column): column is ColumnDefinition => column !== undefined),
);

/**
 * Frozen columns are drawn at the edge they are pinned to.
 *
 * A column pinned to the left renders first even if it was declared fifth,
 * for the reason that makes freezing work at all: a sticky cell is offset by
 * the width of the frozen columns before it, and a frozen column left sitting
 * in the middle would be offset over the top of the ones it was declared
 * after. Moving it is what "pinned" means everywhere else a table offers it,
 * and it is visible — unlike the alternative, which is a column that quietly
 * declines to freeze.
 *
 * Relative order within each group is the order they were declared in.
 */
const visibleColumns = computed(() => [
    ...orderedColumns.value.filter((column) => column.frozen === 'start'),
    ...orderedColumns.value.filter((column) => column.frozen === null),
    ...orderedColumns.value.filter((column) => column.frozen === 'end'),
]);

const hasRowActions = computed(() =>
    props.rows.some((row) => row.actions.length > 0),
);

/**
 * A separate actions column exists unless the buttons live in the last cell.
 * `after_cells` is for a table narrow enough that a column of its own would
 * be most of it.
 *
 * Declared before the frozen set rather than after it, and that is load
 * bearing: `useFrozenColumns` watches the set, Vue evaluates a watch source
 * once to find out what it depends on, and a `const` read during that first
 * evaluation but declared further down the file is a `ReferenceError` in the
 * temporal dead zone — which took the whole table down with it, at setup,
 * before a single row was drawn.
 */
const hasActionsColumn = computed(
    () => hasRowActions.value && actionsPosition.value !== 'after_cells',
);

/*
 * Freezing, and the cells that take part in it.
 *
 * The structural cells are pinned with the columns rather than on their own
 * account — see `TableSchema::hasFrozenStart()`. Their keys are prefixed so
 * they cannot collide with a column actually called `select`.
 */
const REORDER_KEY = '@reorder';
const SELECT_KEY = '@select';
const ACTIONS_KEY = '@actions';

const tableRoot = ref<HTMLElement | null>(null);

/**
 * The sort state of one header cell.
 *
 * `aria-sort` belongs on the `<th>`, not on the button inside it: the cell is
 * what the table's own semantics attach to, and a screen reader reading a
 * column announces the header, not the control. It was nowhere at all — the
 * direction existed only as an arrow icon, which says nothing to anything not
 * looking at it.
 *
 * `none` on a sortable column that is not currently sorted, and *nothing* on a
 * column that cannot be sorted: `aria-sort="none"` is a claim that this column
 * takes part in sorting, which a non-sortable one does not.
 */
function sortStateOf(
    column: ColumnDefinition,
): 'ascending' | 'descending' | 'none' | undefined {
    if (!column.sortable) {
        return undefined;
    }

    if (props.state.sort !== column.name) {
        return 'none';
    }

    return props.state.direction === 'asc' ? 'ascending' : 'descending';
}

/**
 * What a row is called, for the controls that act on it.
 *
 * The record key was being used as the accessible name — "Select row 4821" —
 * which is an identifier, not a name. The first visible cell is what somebody
 * reading the table would call the row, so it is used when it is text, and the
 * key remains the fallback for a table whose first column is an image or a
 * badge.
 */
function rowLabel(row: TableRowData): string {
    for (const column of visibleColumns.value) {
        const cell = row.cells[column.name];
        const value =
            typeof cell === 'object' && cell !== null
                ? (cell as { value?: unknown }).value
                : cell;

        if (typeof value === 'string' && value.trim() !== '') {
            return value;
        }
    }

    return String(row.key);
}

/** 1-based, as a person counts, and as the announcement says it. */
function rowPosition(key: string | number): number {
    return props.rows.findIndex((row) => row.key === key) + 1;
}

/**
 * The announcement after a keyboard move.
 *
 * A live region rather than an alert: it is a confirmation of something the
 * user just did, not an interruption. Cleared and re-set so two moves in a row
 * are both announced even when the sentence would be identical.
 */
const reorderAnnouncement = ref('');

/**
 * Moves one row and says where it went.
 *
 * The same operation the drag path performs: it produces the whole new order
 * and hands it to the one `reorder` emit. Nothing here talks to a server.
 */
function moveRow(key: string | number, offset: number): void {
    const keys = props.rows.map((row) => row.key);
    const from = keys.indexOf(key);
    const to = from + offset;

    if (from === -1 || to < 0 || to >= keys.length) {
        return;
    }

    const row = props.rows[from];

    keys.splice(to, 0, ...keys.splice(from, 1));

    emit('reorder', keys);

    reorderAnnouncement.value = '';

    void nextTick(() => {
        reorderAnnouncement.value = t('tables.reorder_announcement', {
            record: rowLabel(row),
            position: to + 1,
            total: keys.length,
        });
    });

    // Focus follows the record, not the slot it used to occupy — the same
    // correctness the repeater needed after a removal. Without it focus is
    // left on whichever row has slid into the old position.
    void nextTick(() => {
        tableRoot.value
            ?.querySelector<HTMLElement>(
                `[data-reorder-row="${CSS.escape(String(key))}"]`,
            )
            ?.focus();
    });
}

/**
 * The structural cells that sit before and after the data columns.
 *
 * One list, consumed by every horizontal row in the table — the main header,
 * the column-search header, the body and the summary footer. That is the whole
 * of U14: each of those rows used to decide for itself which structural cells
 * to draw, and two of them decided differently. The search row and the footer
 * rendered the actions cell unconditionally at the *end*, so a table with
 * `recordActions.position = before_columns` drew three leading cells in the
 * header and two in the search row: every search input sat under the wrong
 * column, and a stray cell hung off the right.
 *
 * Deriving them instead of repeating them is what makes that impossible rather
 * than merely fixed. A row cannot disagree with the others about the order,
 * because none of them owns an order.
 */
const leadingCells = computed<string[]>(() => {
    const cells: string[] = [];

    if (props.table.reorderable) {
        cells.push(REORDER_KEY);
    }

    if (props.table.selectable) {
        cells.push(SELECT_KEY);
    }

    if (hasActionsColumn.value && actionsPosition.value === 'before_columns') {
        cells.push(ACTIONS_KEY);
    }

    return cells;
});

const trailingCells = computed<string[]>(() =>
    hasActionsColumn.value && actionsPosition.value === 'after_columns'
        ? [ACTIONS_KEY]
        : [],
);

const frozenColumns = computed<FrozenColumn[]>(() => {
    const frozen: FrozenColumn[] = [];

    // From the same list the rows draw, so a frozen table cannot pin cells in
    // an order the header does not use.
    if (props.table.frozen.start) {
        for (const key of leadingCells.value) {
            frozen.push({ key, side: 'start' });
        }
    }

    for (const column of visibleColumns.value) {
        if (column.frozen !== null) {
            frozen.push({ key: column.name, side: column.frozen });
        }
    }

    if (
        props.table.frozen.actions &&
        hasActionsColumn.value &&
        actionsPosition.value === 'after_columns'
    ) {
        frozen.push({ key: ACTIONS_KEY, side: 'end' });
    }

    return frozen;
});

const { measure, styleFor, isEdge, sideOf } = useFrozenColumns(
    frozenColumns,
    tableRoot,
);

/**
 * What a frozen cell wears.
 *
 * `bg-inherit` rather than a colour of its own: the row owns the hover and
 * selected background, and a frozen cell painted `bg-background` would be the
 * one cell in the row that never highlights. Opaque it must be — a
 * transparent sticky cell has the scrolling content pass under it — which is
 * why every row that can hold one carries `bg-background`, and why
 * `panel-table-frozen-cell` paints an opaque plate under the inherited
 * colour: a row tinted `bg-muted/30` inherits a colour you can see through,
 * and half-visible scrolling text under a pinned column is the same bug in a
 * politer form.
 *
 * The divider marks where the frozen group ends, so the seam is something the
 * eye can find rather than a place where columns appear to teleport. It is
 * drawn on the side the column is pinned to, from a class rather than from
 * the inline offset, because which edge a cell owns is something this already
 * knows.
 */
function frozenClass(key: string): string[] {
    const side = sideOf(key);

    if (side === null) {
        return [];
    }

    const classes = ['panel-table-frozen-cell', 'bg-inherit'];

    if (isEdge(key)) {
        classes.push(
            'panel-table-frozen-edge',
            `panel-table-frozen-edge-${side}`,
        );
    }

    return classes;
}

defineExpose({ tableInstance, clearSelection, clearColumnSearches });

const { hook } = usePanelStyling();
</script>

<template>
    <div
        ref="tableRoot"
        :class="[bordered ? 'rounded-lg border' : '', hook('table')]"
    >
        <!--
            Where a row went, after it was moved from the keyboard. Polite and
            restrained: a confirmation of something the user just did, not an
            interruption. A drag says nothing here — the pointer already showed
            the result.
        -->
        <p role="status" aria-live="polite" class="sr-only">
            {{ reorderAnnouncement }}
        </p>
        <Table>
            <TableHeader>
                <TableRow class="bg-background hover:bg-background">
                    <TableHead
                        v-for="key in leadingCells"
                        :key="key"
                        :ref="measure(key)"
                        :class="[
                            key === ACTIONS_KEY ? 'w-12' : 'w-10',
                            ...frozenClass(key),
                        ]"
                        :style="styleFor(key, true)"
                    >
                        <span v-if="key === REORDER_KEY" class="sr-only">
                            {{ t('tables.reorder') }}
                        </span>
                        <Checkbox
                            v-else-if="key === SELECT_KEY"
                            :model-value="allSelected"
                            :aria-label="t('tables.select_all_rows')"
                            @update:model-value="
                                (checked) => toggleAll(checked === true)
                            "
                        />
                        <span v-else class="sr-only">
                            {{
                                table.recordActions.label ?? t('tables.actions')
                            }}
                        </span>
                    </TableHead>
                    <TableHead
                        v-for="column in visibleColumns"
                        :key="column.name"
                        :ref="measure(column.name)"
                        :aria-sort="sortStateOf(column)"
                        :class="[
                            ALIGNMENT_CLASSES[column.headerAlignment],
                            column.wrapHeader ? '' : 'whitespace-nowrap',
                            ...frozenClass(column.name),
                        ]"
                        :style="{
                            ...(column.width ? { width: column.width } : {}),
                            ...styleFor(column.name, true),
                        }"
                        :title="column.headerTooltip ?? undefined"
                    >
                        <button
                            v-if="column.sortable"
                            type="button"
                            class="inline-flex items-center gap-1 rounded-sm font-medium hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            :aria-label="
                                t('tables.sort_by', { column: column.label })
                            "
                            @click="emit('sort', column.name)"
                        >
                            {{ column.label }}
                            <ArrowUp
                                v-if="
                                    state.sort === column.name &&
                                    state.direction === 'asc'
                                "
                                class="size-3.5"
                            />
                            <ArrowDown
                                v-else-if="state.sort === column.name"
                                class="size-3.5"
                            />
                            <ChevronsUpDown
                                v-else
                                class="size-3.5 text-muted-foreground/60"
                            />
                        </button>
                        <span v-else>{{ column.label }}</span>
                    </TableHead>
                    <TableHead
                        v-for="key in trailingCells"
                        :key="key"
                        :ref="measure(key)"
                        class="w-12"
                        :class="frozenClass(key)"
                        :style="styleFor(key, true)"
                    >
                        <span class="sr-only">
                            {{
                                table.recordActions.label ?? t('tables.actions')
                            }}
                        </span>
                    </TableHead>
                </TableRow>

                <!--
                    A second header row rather than boxes crammed beside the
                    labels: a per-column search narrows what the table-wide one
                    already found, and reads as a separate act.
                -->
                <TableRow
                    v-if="hasColumnSearch"
                    class="bg-background hover:bg-background"
                >
                    <TableHead
                        v-for="key in leadingCells"
                        :key="key"
                        :class="[
                            key === ACTIONS_KEY ? 'w-12' : 'w-10',
                            ...frozenClass(key),
                        ]"
                        :style="styleFor(key, true)"
                    />
                    <TableHead
                        v-for="column in visibleColumns"
                        :key="column.name"
                        class="py-2"
                        :class="frozenClass(column.name)"
                        :style="styleFor(column.name, true)"
                    >
                        <Input
                            v-if="column.individuallySearchable"
                            :model-value="columnTerms[column.name] ?? ''"
                            class="h-8"
                            type="search"
                            :placeholder="
                                t('tables.search_column', {
                                    column: column.label,
                                })
                            "
                            :aria-label="
                                t('tables.search_column', {
                                    column: column.label,
                                })
                            "
                            @update:model-value="
                                (value) =>
                                    onColumnSearch(column.name, String(value))
                            "
                        />
                    </TableHead>
                    <TableHead
                        v-for="key in trailingCells"
                        :key="key"
                        class="w-12"
                        :class="frozenClass(key)"
                        :style="styleFor(key, true)"
                    />
                </TableRow>
            </TableHeader>

            <TableBody>
                <TableRow v-if="rows.length === 0">
                    <TableCell :colspan="columnCount" class="p-0">
                        <component
                            :is="emptyStateComponent"
                            v-if="emptyStateComponent"
                            :empty-state="table.emptyState"
                        />
                        <EmptyState
                            v-else
                            class="border-0"
                            :heading="table.emptyState.heading"
                            :description="table.emptyState.description"
                            :icon="table.emptyState.icon"
                        >
                            <!--
                                An empty table is where an action is most
                                useful and least likely to be found, because
                                every other affordance is about rows.
                            -->
                            <template #actions>
                                <ActionButton
                                    v-for="action in table.emptyState.actions"
                                    :key="action.name"
                                    :action="action"
                                    @run="emit('runTableAction', action)"
                                />
                            </template>
                        </EmptyState>
                    </TableCell>
                </TableRow>

                <template v-for="(row, index) in rows" :key="row.key">
                    <TableRow
                        v-if="groupBreaks.has(index)"
                        class="bg-muted/50 hover:bg-muted/50"
                    >
                        <TableCell :colspan="columnCount" class="py-2">
                            <span class="text-sm font-medium">
                                {{ row.group?.title }}
                            </span>
                            <span
                                v-if="row.group?.description"
                                class="ml-2 text-xs text-muted-foreground"
                            >
                                {{ row.group.description }}
                            </span>
                        </TableCell>
                    </TableRow>

                    <TableRow
                        :data-state="
                            isRowSelected(row.key) ? 'selected' : undefined
                        "
                        :draggable="table.reorderable && dragging === row.key"
                        :class="[
                            'bg-background hover:bg-muted',
                            hook('table-row'),
                            dragging === row.key ? 'opacity-60' : undefined,
                        ]"
                        @dragover.prevent
                        @drop.prevent="onDrop(row.key)"
                        @dragend="dragging = null"
                    >
                        <TableCell
                            v-for="key in leadingCells"
                            :key="key"
                            :class="[
                                key === ACTIONS_KEY ? 'w-12' : 'w-10',
                                ...frozenClass(key),
                            ]"
                            :style="styleFor(key)"
                        >
                            <RowReorderControl
                                v-if="key === REORDER_KEY"
                                :label="rowLabel(row)"
                                :position="rowPosition(row.key)"
                                :total="rows.length"
                                :row-key="row.key"
                                @dragstart="dragging = row.key"
                                @move="(offset) => moveRow(row.key, offset)"
                            />
                            <Checkbox
                                v-else-if="key === SELECT_KEY"
                                :model-value="isRowSelected(row.key)"
                                :aria-label="
                                    t('tables.select_row', {
                                        record: rowLabel(row),
                                    })
                                "
                                @update:model-value="
                                    (checked) =>
                                        toggleRow(row.key, checked === true)
                                "
                            />
                            <ActionGroup
                                v-else
                                :actions="row.actions"
                                @run="
                                    (action) =>
                                        emit('runAction', action, row.key)
                                "
                            />
                        </TableCell>
                        <TableCell
                            v-for="column in visibleColumns"
                            :key="column.name"
                            :class="[
                                ALIGNMENT_CLASSES[column.alignment],
                                ...frozenClass(column.name),
                            ]"
                            :style="styleFor(column.name)"
                            v-bind="row.cellMeta[column.name]?.attributes"
                            :title="row.cellMeta[column.name]?.tooltip"
                        >
                            <!--
                            A linked cell is a real anchor, so it opens in a
                            new tab, copies, and reads as a link. The URL was
                            produced on the server; nothing is resolved here.
                        -->
                            <component
                                :is="cellUrl(row, column) ? 'a' : 'div'"
                                :href="cellUrl(row, column) ?? undefined"
                                :class="
                                    cellUrl(row, column)
                                        ? 'underline-offset-4 hover:underline'
                                        : undefined
                                "
                            >
                                <!--
                                A cell with its own action is a button. The
                                action was resolved per record, so a cell the
                                user may not act on is plain text instead.
                            -->
                                <button
                                    v-if="row.cellMeta[column.name]?.action"
                                    type="button"
                                    class="rounded-sm text-left underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    @click="
                                        emit(
                                            'runAction',
                                            row.cellMeta[column.name]!.action!,
                                            row.key,
                                        )
                                    "
                                >
                                    <DataTableCell
                                        :column="column"
                                        :value="row.cells[column.name] ?? null"
                                        :record-key="row.key"
                                        @edit="
                                            (name, value) =>
                                                emit(
                                                    'editCell',
                                                    row.key,
                                                    name,
                                                    value,
                                                )
                                        "
                                    />
                                </button>
                                <DataTableCell
                                    v-else
                                    :column="column"
                                    :value="row.cells[column.name] ?? null"
                                    :record-key="row.key"
                                    @edit="
                                        (name, value) =>
                                            emit(
                                                'editCell',
                                                row.key,
                                                name,
                                                value,
                                            )
                                    "
                                />
                            </component>

                            <ActionGroup
                                v-if="
                                    hasRowActions &&
                                    actionsPosition === 'after_cells' &&
                                    column.name ===
                                        visibleColumns[
                                            visibleColumns.length - 1
                                        ]?.name
                                "
                                class="ml-2 inline-flex"
                                :actions="row.actions"
                                @run="
                                    (action) =>
                                        emit('runAction', action, row.key)
                                "
                            />
                        </TableCell>
                        <TableCell
                            v-for="key in trailingCells"
                            :key="key"
                            class="w-12 text-right"
                            :class="frozenClass(key)"
                            :style="styleFor(key)"
                        >
                            <ActionGroup
                                :actions="row.actions"
                                @run="
                                    (action) =>
                                        emit('runAction', action, row.key)
                                "
                            />
                        </TableCell>
                    </TableRow>
                    <!--
                        A band's own figures, under the band they describe.
                    -->
                    <template v-if="groupEnds.has(index)">
                        <TableRow
                            v-for="(__, figure) in summaryRowCount"
                            :key="`group-${index}-${figure}`"
                            class="bg-muted/30 hover:bg-muted/30"
                        >
                            <TableCell
                                v-for="key in leadingCells"
                                :key="key"
                                :class="[
                                    key === ACTIONS_KEY ? 'w-12' : 'w-10',
                                    ...frozenClass(key),
                                ]"
                                :style="styleFor(key)"
                            />
                            <TableCell
                                v-for="column in visibleColumns"
                                :key="column.name"
                                :class="[
                                    ALIGNMENT_CLASSES[column.alignment],
                                    ...frozenClass(column.name),
                                ]"
                                :style="styleFor(column.name)"
                            >
                                <template
                                    v-if="
                                        groupSummaries[groupEnds.get(index)!]?.[
                                            column.name
                                        ]?.[figure]
                                    "
                                >
                                    <span class="text-xs text-muted-foreground">
                                        {{
                                            groupSummaries[
                                                groupEnds.get(index)!
                                            ][column.name][figure].label
                                        }}
                                    </span>
                                    <span
                                        class="ml-1.5 font-medium tabular-nums"
                                    >
                                        {{
                                            groupSummaries[
                                                groupEnds.get(index)!
                                            ][column.name][figure].value
                                        }}
                                    </span>
                                </template>
                            </TableCell>
                            <TableCell
                                v-for="key in trailingCells"
                                :key="key"
                                class="w-12"
                                :class="frozenClass(key)"
                                :style="styleFor(key)"
                            />
                        </TableRow>
                    </template>
                </template>
            </TableBody>

            <!--
                A footer row per figure, so two summaries under one column
                line up beneath it rather than being crammed into one cell.
            -->
            <TableFooter v-if="hasSummaries && rows.length > 0">
                <TableRow
                    v-for="(_, index) in summaryRowCount"
                    :key="index"
                    class="bg-background hover:bg-background"
                >
                    <TableCell
                        v-for="key in leadingCells"
                        :key="key"
                        :class="[
                            key === ACTIONS_KEY ? 'w-12' : 'w-10',
                            ...frozenClass(key),
                        ]"
                        :style="styleFor(key)"
                    />
                    <TableCell
                        v-for="column in visibleColumns"
                        :key="column.name"
                        :class="[
                            ALIGNMENT_CLASSES[column.alignment],
                            ...frozenClass(column.name),
                        ]"
                        :style="styleFor(column.name)"
                    >
                        <template v-if="summaries[column.name]?.[index]">
                            <span class="text-xs text-muted-foreground">
                                {{ summaries[column.name][index].label }}
                            </span>
                            <span class="ml-1.5 font-medium tabular-nums">
                                {{ summaries[column.name][index].value }}
                            </span>
                        </template>
                    </TableCell>
                    <TableCell
                        v-for="key in trailingCells"
                        :key="key"
                        class="w-12"
                        :class="frozenClass(key)"
                        :style="styleFor(key)"
                    />
                </TableRow>
            </TableFooter>
        </Table>
    </div>
</template>
