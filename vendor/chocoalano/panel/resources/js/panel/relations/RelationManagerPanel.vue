<script setup lang="ts">
import { computed, ref } from 'vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import ActionButton from '@/panel/actions/ActionButton.vue';
import ActionModal from '@/panel/actions/ActionModal.vue';
import { useRelationActions } from '@/panel/composables/useRelationActions';
import { useRelationTable } from '@/panel/composables/useRelationTable';
import RelationFormDialog from '@/panel/relations/RelationFormDialog.vue';
import DataTable from '@/panel/tables/DataTable.vue';
import DataTableBulkActions from '@/panel/tables/DataTableBulkActions.vue';
import DataTableColumnManager from '@/panel/tables/DataTableColumnManager.vue';
import DataTablePagination from '@/panel/tables/DataTablePagination.vue';
import DataTableIntro from '@/panel/tables/DataTableIntro.vue';
import DataTableToolbar from '@/panel/tables/DataTableToolbar.vue';
import type { ActionDefinition } from '@/panel/types/action';
import type { RelationDefinition } from '@/panel/types/relation';

/**
 * One relation manager, rendered from the same table components a resource
 * index uses.
 *
 * It holds no state of its own beyond the current selection and which dialog
 * is open. Sorting, searching, filtering, and paging all write to this
 * relation's own slice of the query string and let the server answer, so
 * several of these on one page never move each other.
 */
const props = defineProps<{
    relation: RelationDefinition;
    /** The resource slug and owner key every write is posted with. */
    resource: string;
    record: string | number;
}>();

const {
    setSearch,
    setSort,
    setPage,
    setPerPage,
    setFilter,
    setFilters,
    setColumnSearch,
    setColumns,
    resetColumns,
    clearFilters,
} = useRelationTable(() => props.relation);

const {
    pending,
    processing,
    formUrl,
    formContext,
    runRecord,
    runBulk,
    runHeader,
    confirm,
    cancel,
} = useRelationActions(
    () => ({
        resource: props.resource,
        record: props.record,
        relation: props.relation.key,
    }),
    () => props.relation.endpoints,
);

const selected = ref<Array<string | number>>([]);
const tableRef = ref<InstanceType<typeof DataTable> | null>(null);
const openFormUrl = ref<string | null>(null);

/**
 * One rule, and it is the same for a row action and a header action:
 *
 *     the action carries a form  ->  open a form
 *
 * Two kinds of form, decided by the server rather than by the caller. An
 * action carrying a `formUrl` is one of the relation's own operations —
 * create, edit, attach, associate — whose URL names an owner and an operation
 * this component knows nothing about, so `RelationFormDialog` is handed it as
 * given. An action carrying its own schema is an ordinary action that happens
 * to collect something first, and it opens the same `ActionModal` a resource
 * action does.
 *
 * What it used to be was "has a form *and* happens to have had a formUrl
 * injected", which is why an action declaring its own schema fell through and
 * ran immediately — no dialog, no values, a handler called with an empty
 * array. A header action declaring one did nothing at all.
 */
function openOrRun(action: ActionDefinition, run: () => void): void {
    if (action.type === 'form' && action.formUrl !== null) {
        openFormUrl.value = action.formUrl;

        return;
    }

    run();
}

function onRunAction(action: ActionDefinition, record: string | number): void {
    openOrRun(action, () => runRecord(action, record));
}

function onRunHeaderAction(action: ActionDefinition): void {
    openOrRun(action, () => runHeader(action));
}

/**
 * An action registered on an open dialog. It is about the same related record
 * its parent was, which is the only record in scope while that dialog is open.
 */
function onRunModalAction(action: ActionDefinition): void {
    const related = pending.value?.related ?? null;

    if (related !== null) {
        onRunAction(action, related);
    }
}

function onRunBulk(action: ActionDefinition): void {
    runBulk(action, selected.value);
}

function clearSelection(): void {
    tableRef.value?.clearSelection();
    selected.value = [];
}

function clearTableFilters(): void {
    tableRef.value?.clearColumnSearches();
    clearFilters();
}

const headerActions = computed(() => props.relation.headerActions);
</script>

<template>
    <Card>
        <CardHeader
            class="flex flex-row flex-wrap items-center justify-between gap-2"
        >
            <CardTitle>{{ relation.title }}</CardTitle>

            <div class="flex items-center gap-2">
                <ActionButton
                    v-for="action in headerActions"
                    :key="action.name"
                    :action="action"
                    size="sm"
                    @run="onRunHeaderAction(action)"
                />
            </div>
        </CardHeader>

        <CardContent class="flex flex-col gap-4">
            <DataTableIntro
                :description="relation.table.description"
                :callouts="relation.table.callouts"
            />

            <DataTableToolbar
                :table="relation.table"
                :state="relation.state"
                @search="setSearch"
                @filter="setFilter"
                @filters="setFilters"
                @clear="clearTableFilters"
            >
                <template #actions>
                    <DataTableColumnManager
                        :table="relation.table"
                        :visible="relation.state.columns.visible"
                        :order="relation.state.columns.order"
                        @change="setColumns"
                        @reset="resetColumns"
                    />
                </template>
            </DataTableToolbar>

            <DataTableBulkActions
                :actions="relation.table.bulkActions"
                :selected="selected"
                :processing="processing"
                @run="onRunBulk"
                @clear="clearSelection"
            />

            <DataTable
                ref="tableRef"
                :table="relation.table"
                :rows="relation.rows"
                :state="relation.state"
                :summaries="relation.summaries"
                :group-summaries="relation.groupSummaries"
                @sort="setSort"
                @column-search="setColumnSearch"
                @selection-change="(keys) => (selected = keys)"
                @run-action="onRunAction"
            />

            <DataTablePagination
                :pagination="relation.pagination"
                :per-page-options="relation.table.perPageOptions"
                @page="setPage"
                @per-page="setPerPage"
            />
        </CardContent>

        <ActionModal
            :action="pending?.action ?? null"
            :processing="processing"
            :form-url="formUrl"
            :context="formContext"
            @confirm="confirm"
            @cancel="cancel"
            @saved="cancel"
            @run="onRunModalAction"
        />

        <RelationFormDialog
            :form-url="openFormUrl"
            @close="openFormUrl = null"
        />
    </Card>
</template>
