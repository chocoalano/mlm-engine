<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ChevronDown, ChevronUp, Search } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import DataTableCell from '@/panel/tables/DataTableCell.vue';
import type {
    ColumnDefinition,
    PaginationMeta,
    TableRow as Row,
    TableState,
} from '@/panel/types/table';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

/**
 * A dashboard table, built by the table builder.
 *
 * Search, sorting, and pagination all work, and every one of them is a query
 * parameter under this widget's namespace — `widgets[recent-users][page]`.
 * That namespace is what makes two table widgets on one dashboard possible at
 * all, and it is the same arrangement a relation manager uses.
 *
 * Still not a resource index: no bulk actions, no column manager, no filter
 * tabs. A widget is a summary you can look through, not a second place
 * records are managed from.
 */
const props = defineProps<{
    columns: ColumnDefinition[];
    rows: Row[];
    emptyMessage: string;
    /** Null for a payload written before the widget was paginated. */
    state: TableState | null;
    pagination: PaginationMeta | null;
    namespace: string | null;
    searchable: boolean;
}>();

/**
 * The columns this widget actually draws.
 *
 * `columns` is every column the schema declares, including any the widget
 * marked `visible(false)`. The rows only carry the visible ones, so drawing
 * from the full list put a header over a column of placeholders — a hidden
 * column read as an empty one rather than as absent.
 *
 * A payload written before the widget was paginated has no state, and then
 * every column is what there is.
 */
const visibleColumns = computed(() => {
    const visible = props.state?.columns.visible;

    if (visible === undefined) {
        return props.columns;
    }

    return props.columns.filter((column) => visible.includes(column.name));
});

const search = ref(props.state?.search ?? '');

watch(
    () => props.state?.search,
    (value) => {
        search.value = value ?? '';
    },
);

/**
 * One parameter of this widget's slice of the query string.
 *
 * The namespace is dotted on the server (`widgets.recent-users`) and bracketed
 * in a URL, which is the same value said two ways rather than two values.
 */
function parameter(key: string): string {
    const namespace = props.namespace;

    if (namespace === null) {
        return key;
    }

    return (
        namespace
            .split('.')
            .map((segment, index) => (index === 0 ? segment : `[${segment}]`))
            .join('') + `[${key}]`
    );
}

function go(values: Record<string, string | null>): void {
    const url = new URL(window.location.href);

    for (const [key, value] of Object.entries(values)) {
        if (value === null || value === '') {
            url.searchParams.delete(parameter(key));
        } else {
            url.searchParams.set(parameter(key), value);
        }
    }

    router.visit(url.pathname + url.search, {
        preserveScroll: true,
        preserveState: true,
    });
}

const sortable = computed(
    () =>
        new Set(
            props.columns
                .filter((column) => column.sortable)
                .map((c) => c.name),
        ),
);

function toggleSort(name: string): void {
    if (!sortable.value.has(name)) {
        return;
    }

    const isCurrent = props.state?.sort === name;
    const direction =
        isCurrent && props.state?.direction === 'asc' ? 'desc' : 'asc';

    // Back to the first page: page four of one order is not page four of
    // another.
    go({ sort: name, direction, page: null });
}

function submitSearch(): void {
    go({ search: search.value === '' ? null : search.value, page: null });
}

const page = computed(() => props.pagination?.page ?? 1);
const lastPage = computed(() => props.pagination?.lastPage ?? 1);
</script>

<template>
    <div class="flex flex-col gap-3">
        <form
            v-if="searchable && namespace"
            class="flex items-center gap-2 rounded-lg border border-border/70 bg-background/60 p-2"
            @submit.prevent="submitSearch"
        >
            <div class="relative flex-1">
                <Search
                    class="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="search"
                    class="h-9 border-0 bg-transparent pl-8 shadow-none focus-visible:ring-0"
                    :placeholder="t('widgets.search')"
                    :aria-label="t('widgets.search_table')"
                    @blur="submitSearch"
                />
            </div>
        </form>

        <!-- `py-0` and `overflow-hidden`: the rows sit flush inside the card
             and the header row is clipped by its radius. -->
        <Card
            class="overflow-hidden rounded-lg border-border/70 py-0 shadow-xs"
        >
            <Table>
                <TableHeader>
                    <TableRow class="bg-muted/25 hover:bg-muted/25">
                        <TableHead
                            v-for="column in visibleColumns"
                            :key="column.name"
                        >
                            <button
                                v-if="column.sortable && namespace"
                                type="button"
                                class="flex items-center gap-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase transition-colors hover:text-foreground"
                                @click="toggleSort(column.name)"
                            >
                                {{ column.label }}
                                <ChevronUp
                                    v-if="
                                        state?.sort === column.name &&
                                        state?.direction === 'asc'
                                    "
                                    class="size-3"
                                />
                                <ChevronDown
                                    v-else-if="state?.sort === column.name"
                                    class="size-3"
                                />
                            </button>
                            <template v-else>
                                <span
                                    class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                                    >{{ column.label }}</span
                                >
                            </template>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-if="rows.length === 0">
                        <TableCell
                            :colspan="visibleColumns.length"
                            class="h-28 py-8 text-center text-sm text-muted-foreground"
                        >
                            {{
                                emptyMessage === ''
                                    ? t('widgets.empty')
                                    : emptyMessage
                            }}
                        </TableCell>
                    </TableRow>
                    <TableRow v-for="row in rows" :key="row.key">
                        <TableCell
                            v-for="column in visibleColumns"
                            :key="column.name"
                        >
                            <DataTableCell
                                :column="column"
                                :value="row.cells[column.name] ?? null"
                            />
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </Card>

        <div
            v-if="pagination && lastPage > 1"
            class="flex items-center justify-between border-t border-border/60 pt-3 text-xs text-muted-foreground"
        >
            <span>
                {{ pagination.from }}–{{ pagination.to }} of
                {{ pagination.total }}
            </span>
            <div class="flex items-center gap-1">
                <Button
                    variant="ghost"
                    size="sm"
                    :disabled="page <= 1"
                    @click="go({ page: String(page - 1) })"
                >
                    {{ t('widgets.previous') }}
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    :disabled="page >= lastPage"
                    @click="go({ page: String(page + 1) })"
                >
                    {{ t('widgets.next') }}
                </Button>
            </div>
        </div>
    </div>
</template>
