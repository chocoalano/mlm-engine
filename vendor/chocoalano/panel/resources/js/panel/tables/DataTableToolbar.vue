<script setup lang="ts">
import { Search, X } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import ActionButton from '@/panel/actions/ActionButton.vue';
import { resolveIcon } from '@/panel/icons/registry';
import DataTableFilters from '@/panel/tables/DataTableFilters.vue';
import DataTableSortMenu from '@/panel/tables/DataTableSortMenu.vue';
import { completeRules, hasIncomplete } from '@/panel/tables/queryBuilderRules';
import type { ActionDefinition } from '@/panel/types/action';
import type {
    FilterDefinition,
    FilterValue,
    QueryBuilderRule,
    TableDefinition,
    TableState,
} from '@/panel/types/table';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

const props = defineProps<{
    table: TableDefinition;
    state: TableState;
}>();

const emit = defineEmits<{
    search: [term: string];
    filter: [name: string, value: FilterValue | null];
    filters: [values: Record<string, FilterValue | null>];
    runAction: [action: ActionDefinition];
    sort: [column: string];
    clear: [];
}>();

const behaviour = computed(() => props.table.filterBehaviour);

const triggerIcon = computed(() => resolveIcon(behaviour.value.triggerIcon));

/**
 * Held changes, for a table that defers.
 *
 * Local only while the user is composing: the moment they apply, the URL
 * becomes the state again and this is discarded. Anything still pending when
 * the server answers is dropped, because the server's answer is the truth.
 */
const pending = ref<Record<string, FilterValue | null>>({});

const hasPending = computed(() => Object.keys(pending.value).length > 0);

/**
 * Query-builder rules the user is still composing.
 *
 * A rule with no value yet is not a condition the server can run, and it
 * rightly drops it. On a table that filters immediately that answer used to
 * come straight back and replace the editor's contents, so the row somebody
 * had just added to fill in disappeared under them — which made "Add
 * condition" unusable without deferring the whole table.
 *
 * So the editor keeps its own copy while any rule is incomplete, and sends
 * only the ones the server can execute. Held per filter, and only for as
 * long as it differs from what the server applied: once every rule is
 * complete the server's answer says the same thing, and a second copy of it
 * would only be a way for the two to disagree.
 */
const drafts = ref<Record<string, QueryBuilderRule[]>>({});

function definitionFor(name: string): FilterDefinition | undefined {
    return props.table.filters.find((filter) => filter.name === name);
}

watch(
    () => props.state.filters,
    () => {
        pending.value = {};
    },
);

/**
 * A deferred table shows what is being composed; a live one shows what the
 * server applied. Either way the controls render from one map.
 */
const filterValues = computed<Record<string, FilterValue>>(() => {
    if (!behaviour.value.deferred) {
        // What the server applied, plus whatever is still being composed in a
        // query builder. Without the second half an incomplete rule vanishes
        // the moment the server answers.
        return Object.keys(drafts.value).length === 0
            ? props.state.filters
            : { ...props.state.filters, ...drafts.value };
    }

    const merged: Record<string, FilterValue> = { ...props.state.filters };

    for (const [name, value] of Object.entries(pending.value)) {
        if (value === null) {
            delete merged[name];
        } else {
            merged[name] = value;
        }
    }

    return merged;
});

function onFilter(name: string, value: FilterValue | null): void {
    if (behaviour.value.deferred) {
        pending.value = { ...pending.value, [name]: value };

        return;
    }

    emit('filter', name, executable(name, value));
}

/**
 * The part of a filter's value the server can act on, remembering the rest.
 *
 * Only query builders have a "rest": every other filter is a single value
 * that is either set or not. A rule the server would refuse is kept here and
 * merged back over its answer, so the row survives while the user fills it
 * in — and is forgotten as soon as it no longer needs to be.
 */
function executable(
    name: string,
    value: FilterValue | null,
): FilterValue | null {
    const filter = definitionFor(name);

    if (
        filter === undefined ||
        filter.type !== 'query_builder' ||
        !Array.isArray(value)
    ) {
        delete drafts.value[name];

        return value;
    }

    const rules = value as QueryBuilderRule[];

    if (hasIncomplete(filter, rules)) {
        drafts.value = { ...drafts.value, [name]: rules };
    } else {
        const { [name]: _dropped, ...rest } = drafts.value;

        drafts.value = rest;
    }

    const complete = completeRules(filter, rules);

    // Null rather than an empty list, so a filter with nothing executable
    // reads as "not applied" exactly as it did before.
    return complete.length > 0 ? complete : null;
}

function applyFilters(): void {
    emit('filters', pending.value);
    pending.value = {};
}

/**
 * Removing a chip applies at once, on a deferred table too: a chip says what
 * the server is *already* narrowing by, so staging its removal would leave it
 * on screen claiming a filter the user has just taken off.
 *
 * It carries whatever else is being composed into the same visit rather than
 * emitting on its own. The server's answer resets `pending`, so a lone emit
 * threw away filters the user had set but not yet applied.
 */
function removeIndicator(name: string): void {
    if (!behaviour.value.deferred) {
        emit('filter', name, null);

        return;
    }

    emit('filters', { ...pending.value, [name]: null });
    pending.value = {};
}

/**
 * The debounce and the blur behaviour are the server's decision, because the
 * cost of a search is: a table over a large join wants the user to finish
 * typing, a small one can answer every keystroke.
 */
const debounceMs = computed(() => props.table.searchDebounce);

/**
 * The input is the one place local state is justified: the server value only
 * arrives after the debounced visit, and binding straight to it would move
 * the caret while typing. It is re-synced whenever the server value changes,
 * so back and forward still update the box.
 */
const term = ref(props.state.search ?? '');

const emitSearch = useDebounceFn(
    (value: string) => emit('search', value),
    debounceMs,
);

let skipNextSearchEmit = false;

function syncTerm(value: string): void {
    if (term.value === value) {
        return;
    }

    skipNextSearchEmit = true;
    term.value = value;
}

watch(
    () => props.state.search,
    (value) => {
        emitSearch.cancel();
        syncTerm(value ?? '');
    },
);

watch(term, (value) => {
    if (skipNextSearchEmit) {
        skipNextSearchEmit = false;

        return;
    }

    // On blur, typing changes nothing until focus leaves — so the watcher
    // that would ask on every keystroke must not run at all.
    if (!props.table.searchOnBlur) {
        emitSearch(value);
    }
});

function onBlur(): void {
    if (props.table.searchOnBlur) {
        emit('search', term.value);
    }
}

function clearFilters(): void {
    emitSearch.cancel();
    pending.value = {};
    syncTerm('');
    emit('clear');
}

const hasActiveFilters = computed(
    () =>
        Object.keys(props.state.filters).length > 0 ||
        Object.keys(props.state.columnSearches).length > 0 ||
        Object.keys(pending.value).length > 0 ||
        term.value.trim() !== '' ||
        (props.state.search ?? '') !== '',
);

/**
 * The ordering currently in force, when the table named its default.
 *
 * Only shown while no explicit sort is applied: once the user has clicked a
 * header, the header itself says what is sorted.
 */
const defaultSortLabel = computed(() =>
    props.state.sort === null ? (props.table.defaultSort?.label ?? null) : null,
);

/**
 * Whether the toolbar draws the sort control.
 *
 * A card grid has no column headers, so the menu is the only way to sort one
 * — but a table with nothing `sortable()` has no menu to draw either, and the
 * condition has to know that here rather than inside the menu. Guarding only
 * in the menu made it render nothing while still winning the `v-else`, so a
 * grid with a declared default sort and no sortable column lost the plain
 * "Sorted by" statement as well and said nothing at all about its order.
 */
const showsSortMenu = computed(
    () =>
        props.state.layout === 'grid' &&
        props.table.columns.some((column) => column.sortable),
);
</script>

<template>
    <div class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center gap-2">
            <div v-if="table.searchable" class="relative w-full max-w-xs">
                <Search
                    class="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="term"
                    class="h-9 pl-8"
                    type="search"
                    :placeholder="table.searchPlaceholder"
                    :aria-label="table.searchPlaceholder"
                    @blur="onBlur"
                    @keydown.enter="emit('search', term)"
                />
            </div>

            <Button
                v-if="hasActiveFilters && behaviour.showReset"
                variant="ghost"
                size="sm"
                @click="clearFilters"
            >
                <X />
                {{ behaviour.resetLabel }}
            </Button>

            <!--
                In a card grid there are no column headers to sort from, so the
                control has to live here. In the row table the headers are the
                control, and this stays the plain statement it has always been
                — no screen ever shows two ways to sort the same table.
            -->
            <DataTableSortMenu
                v-if="showsSortMenu"
                :table="table"
                :state="state"
                @sort="(column) => emit('sort', column)"
            />

            <p
                v-else-if="defaultSortLabel"
                class="text-sm text-muted-foreground"
            >
                Sorted by {{ defaultSortLabel }}
            </p>

            <div class="ml-auto flex items-center gap-2">
                <ActionButton
                    v-for="action in table.toolbarActions"
                    :key="action.name"
                    :action="action"
                    @run="emit('runAction', action)"
                />
                <slot name="actions" />
            </div>
        </div>

        <!--
            The chips say what is narrowing the table in words the server
            wrote: only a filter knows that `1` means "Verified".
        -->
        <div
            v-if="state.filterIndicators.length > 0"
            class="flex flex-wrap items-center gap-1.5"
        >
            <Badge
                v-for="indicator in state.filterIndicators"
                :key="indicator.name"
                variant="secondary"
                class="gap-1"
            >
                {{ indicator.label }}
                <button
                    type="button"
                    class="rounded-sm hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :aria-label="
                        t('tables.remove_filter', { filter: indicator.label })
                    "
                    @click="removeIndicator(indicator.name)"
                >
                    <X class="size-3" />
                </button>
            </Badge>
        </div>

        <DataTableFilters
            :filters="table.filters"
            :state="state"
            :values="filterValues"
            :column-names="table.columns.map((column) => column.name)"
            @change="onFilter"
        />

        <div v-if="behaviour.deferred" class="flex items-center gap-2">
            <Button size="sm" :disabled="!hasPending" @click="applyFilters">
                <component :is="triggerIcon" v-if="triggerIcon" />
                {{ behaviour.applyLabel }}
            </Button>
            <p v-if="hasPending" class="text-xs text-muted-foreground">
                {{ t('tables.filters_not_applied') }}
            </p>
        </div>
    </div>
</template>
