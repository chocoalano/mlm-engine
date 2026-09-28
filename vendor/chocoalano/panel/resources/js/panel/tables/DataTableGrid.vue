<script setup lang="ts">
import { computed } from 'vue';
import ActionButton from '@/panel/actions/ActionButton.vue';
import EmptyState from '@/panel/components/EmptyState.vue';
import type { CellEditValue } from '@/panel/composables/useActions';
import { usePanelStyling } from '@/panel/composables/usePanelStyling';
import { resolveCardFace } from '@/panel/tables/cardFace';
import DataTableCard from '@/panel/tables/DataTableCard.vue';
import { useEmptyStateComponent } from '@/panel/tables/tableCells';
import { useTableGroups } from '@/panel/tables/useTableGroups';
import { useTableSelection } from '@/panel/tables/useTableSelection';
import type { ActionDefinition } from '@/panel/types/action';
import type {
    TableDefinition,
    TableGroupSummaries,
    TableRow as TableRowData,
    TableState,
    TableSummaries,
} from '@/panel/types/table';

/**
 * The same records as `DataTable`, drawn as a grid of cards.
 *
 * A second renderer over one `TableSchema`, not a second page: the query, the
 * whitelist, the state and the URL are the identical ones the row table uses.
 * Filters, search, tabs and pagination are drawn by the page around this and
 * never knew which renderer they were sitting above.
 *
 * What does not come along is anything that means "a column of a table":
 * frozen columns have no sideways scroll to pin against, per-column search is
 * a second header row, and column widths are table tracks. Those are inert
 * here rather than broken — the schema may declare them, and this simply has
 * no header to apply them to.
 */
const props = withDefaults(
    defineProps<{
        table: TableDefinition;
        rows: TableRowData[];
        state: TableState;
        summaries?: TableSummaries;
        /** Per band, drawn under the run of cards that band heads. */
        groupSummaries?: TableGroupSummaries;
    }>(),
    { summaries: () => ({}), groupSummaries: () => ({}) },
);

const emit = defineEmits<{
    runTableAction: [action: ActionDefinition];
    editCell: [record: string | number, column: string, value: CellEditValue];
    selectionChange: [keys: Array<string | number>];
    runAction: [action: ActionDefinition, record: string | number];
}>();

const { toggleRow, isRowSelected, clearSelection } = useTableSelection(
    () => props.rows,
    () => props.table.columns,
    (keys) => emit('selectionChange', keys),
);

const { breaks: groupBreaks, ends: groupEnds } = useTableGroups(
    () => props.rows,
);

const emptyStateComponent = useEmptyStateComponent(() => props.table);

/**
 * Resolved once for the run rather than per card.
 *
 * `table.cards` is non-null whenever this component is mounted — the server
 * only offers the grid layout for a table that has a card face — but the type
 * cannot say so, and a fallback face is a cheaper answer than a crash.
 */
const face = computed(() =>
    resolveCardFace(
        props.table.cards ?? {
            columns: 3,
            image: null,
            title: null,
            description: null,
            badges: [],
            details: [],
        },
        props.table.columns,
        props.state.columns.visible,
    ),
);

/**
 * Figures survive the switch to cards, as a strip rather than a footer row.
 *
 * A "Sum: 1,240" that disappears because somebody changed how the list is
 * drawn is a real loss on a ledger. There are no columns for a figure to sit
 * under here, so the column name becomes part of the label instead.
 */
function figuresIn(summaries: TableSummaries): SummaryStripFigure[] {
    return Object.entries(summaries).flatMap(([column, figures]) =>
        figures.map((figure) => ({
            key: `${column}-${figure.name}`,
            label: figure.label,
            value: figure.value,
        })),
    );
}

interface SummaryStripFigure {
    key: string;
    label: string;
    value: string;
}

const summaryFigures = computed(() => figuresIn(props.summaries));

/**
 * A band's own figures, drawn under the run of cards that band heads.
 *
 * Keyed by the index of the band's *last* row, which is where the run ends —
 * the same answer `DataTable` uses to place a per-band footer, read from the
 * same composable so the two renderers cannot disagree about where a band
 * stops.
 */
const groupFigures = computed(() => {
    const figures = new Map<number, SummaryStripFigure[]>();

    for (const [index, key] of groupEnds.value) {
        const summaries = props.groupSummaries[key];

        if (summaries !== undefined) {
            figures.set(index, figuresIn(summaries));
        }
    }

    return figures;
});

/**
 * A deliberate no-op.
 *
 * The page clears both the selection and the column search terms through one
 * template ref, and a grid has no per-column search boxes to clear. Paying one
 * empty method keeps the page from having to ask which renderer it is holding.
 */
function clearColumnSearches(): void {}

defineExpose({ clearSelection, clearColumnSearches });

const { hook } = usePanelStyling();
</script>

<template>
    <!--
        The `table` hook rather than one of its own. A panel that styled
        `panel-table` meant this table, and a reader switching to cards has not
        moved to a different object — adding a second name would make the
        theming depend on which renderer happened to be showing.
    -->
    <div :class="hook('table')" class="flex flex-col gap-4">
        <div
            v-if="rows.length > 0"
            class="grid gap-4"
            :class="face.gridClasses"
        >
            <template v-for="(row, index) in rows" :key="row.key">
                <!--
                    A band heading spans the whole run, so the cards below it
                    read as belonging to it however many fit on a line.
                -->
                <h3
                    v-if="groupBreaks.has(index) && row.group"
                    class="col-span-full pt-1 text-sm font-medium text-muted-foreground"
                >
                    {{ row.group.title }}
                    <span
                        v-if="row.group.description"
                        class="font-normal opacity-80"
                    >
                        — {{ row.group.description }}
                    </span>
                </h3>

                <DataTableCard
                    :face="face"
                    :row="row"
                    :selectable="table.selectable"
                    :selected="isRowSelected(row.key)"
                    @select="(checked) => toggleRow(row.key, checked)"
                    @run-action="(action) => emit('runAction', action, row.key)"
                    @edit-cell="
                        (column, value) =>
                            emit('editCell', row.key, column, value)
                    "
                />

                <!--
                    A band's figures close the run it heads, so a total reads
                    the way a column of numbers does — under what it describes.
                -->
                <div
                    v-if="groupFigures.has(index)"
                    class="col-span-full flex flex-wrap items-center gap-x-4 gap-y-1 border-t pt-2 pb-1"
                >
                    <p
                        v-for="figure in groupFigures.get(index)"
                        :key="figure.key"
                        class="text-sm"
                    >
                        <span class="text-xs text-muted-foreground">
                            {{ figure.label }}
                        </span>
                        <span class="ml-1.5 font-medium tabular-nums">
                            {{ figure.value }}
                        </span>
                    </p>
                </div>
            </template>
        </div>

        <component
            :is="emptyStateComponent"
            v-else-if="emptyStateComponent"
            :empty-state="table.emptyState"
        />

        <EmptyState
            v-else
            :heading="table.emptyState.heading"
            :description="table.emptyState.description"
            :icon="table.emptyState.icon"
        >
            <template #actions>
                <ActionButton
                    v-for="action in table.emptyState.actions"
                    :key="action.name"
                    :action="action"
                    @run="emit('runTableAction', action)"
                />
            </template>
        </EmptyState>

        <div
            v-if="summaryFigures.length > 0 && rows.length > 0"
            class="flex flex-wrap items-center gap-x-4 gap-y-1 border-t pt-3"
        >
            <p
                v-for="figure in summaryFigures"
                :key="figure.key"
                class="text-sm"
            >
                <span class="text-xs text-muted-foreground">
                    {{ figure.label }}
                </span>
                <span class="ml-1.5 font-medium tabular-nums">
                    {{ figure.value }}
                </span>
            </p>
        </div>
    </div>
</template>
