<script setup lang="ts">
import { ArrowDown, ArrowUp, ArrowUpDown } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { TableDefinition, TableState } from '@/panel/types/table';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

/**
 * The sort control for a layout that has no column headers.
 *
 * In the row table, sorting *is* the header — clicking one sorts by it and
 * clicking it again reverses. A card grid has no header, so without this the
 * grid could not be sorted at all.
 *
 * It emits the same `sort` event with the same column name the header button
 * does, so the page hands it the identical `setSort` handler. That is what
 * makes clicking a different column sort ascending and clicking the active one
 * reverse, with no second copy of the rule that decides it.
 *
 * There are deliberately no separate Ascending and Descending items: two
 * controls with different interaction models for one piece of state is worse
 * than one control with fewer affordances, and the arrow already says which
 * way the next click goes.
 */
const props = defineProps<{
    table: TableDefinition;
    state: TableState;
}>();

const emit = defineEmits<{ sort: [column: string] }>();

const sortable = computed(() =>
    props.table.columns.filter((column) => column.sortable),
);

const active = computed(
    () =>
        props.table.columns.find(
            (column) => column.name === props.state.sort,
        ) ?? null,
);

const directionIcon = computed(() =>
    props.state.direction === 'asc' ? ArrowUp : ArrowDown,
);

/**
 * What the trigger says.
 *
 * Falls back to the label the server already sends for the table's declared
 * ordering — the same string the toolbar shows as plain text in table layout,
 * so the two never describe the same table differently.
 */
const label = computed(() => {
    if (active.value !== null) {
        return t('tables.sorted_by', { column: active.value.label });
    }

    const declared = props.table.defaultSort?.label;

    return declared
        ? t('tables.sorted_by', { column: declared })
        : t('tables.sort');
});
</script>

<template>
    <DropdownMenu v-if="sortable.length > 0">
        <DropdownMenuTrigger as-child>
            <Button variant="outline" size="sm">
                <ArrowUpDown />
                {{ label }}
                <component :is="directionIcon" v-if="active" class="size-3.5" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start">
            <DropdownMenuItem
                v-for="column in sortable"
                :key="column.name"
                @select="emit('sort', column.name)"
            >
                {{ column.label }}
                <component
                    :is="directionIcon"
                    v-if="state.sort === column.name"
                    class="ml-auto size-3.5"
                />
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
