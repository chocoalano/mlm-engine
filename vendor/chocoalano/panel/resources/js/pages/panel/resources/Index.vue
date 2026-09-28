<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ActionButton from '@/panel/actions/ActionButton.vue';
import ActionModal from '@/panel/actions/ActionModal.vue';
import PageHeader from '@/panel/components/PageHeader.vue';
import { useActions } from '@/panel/composables/useActions';
import { useResource } from '@/panel/composables/useResource';
import DataTable from '@/panel/tables/DataTable.vue';
import DataTableBulkActions from '@/panel/tables/DataTableBulkActions.vue';
import DataTableColumnManager from '@/panel/tables/DataTableColumnManager.vue';
import DataTableGrid from '@/panel/tables/DataTableGrid.vue';
import DataTableLayoutToggle from '@/panel/tables/DataTableLayoutToggle.vue';
import DataTableIntro from '@/panel/tables/DataTableIntro.vue';
import DataTablePagination from '@/panel/tables/DataTablePagination.vue';
import DataTableTabs from '@/panel/tables/DataTableTabs.vue';
import DataTableToolbar from '@/panel/tables/DataTableToolbar.vue';
import type { ActionDefinition, ActionEndpoints } from '@/panel/types/action';
import type { PageMetadata } from '@/panel/types/page';
import type {
    PaginationMeta,
    ResourceMeta,
    TableDefinition,
    TableRow,
    TableState,
    TableGroupSummaries,
    TableSummaries,
    TableTab,
} from '@/panel/types/table';
import type { WidgetData, WidgetDefinition } from '@/panel/types/widget';
import PageWidgets from '@/panel/widgets/PageWidgets.vue';
import PanelLayout from '@/panel/layouts/PanelLayout.vue';

defineOptions({ layout: PanelLayout });

const props = withDefaults(
    defineProps<{
        page: PageMetadata;
        resource: ResourceMeta;
        table: TableDefinition;
        state: TableState;
        rows: TableRow[];
        pagination: PaginationMeta;
        /** Figures under the table; empty for one that declares none. */
        summaries?: TableSummaries;
        groupSummaries?: TableGroupSummaries;
        actionEndpoints: ActionEndpoints;
        /** Filter tabs; empty for a table that declares none. */
        tabs?: TableTab[];
        /** Placed by the page above and below its own content. */
        headerWidgets?: WidgetDefinition[];
        footerWidgets?: WidgetDefinition[];
        /** Deferred: absent from the first response, so optional. */
        widgetData?: WidgetData | null;
    }>(),
    {
        summaries: () => ({}),
        groupSummaries: () => ({}),
        tabs: () => [],
        headerWidgets: () => [],
        footerWidgets: () => [],
        widgetData: null,
    },
);

/**
 * The generic index page for every resource. It holds no table state of its
 * own: every control writes to the URL and the server answers with the props
 * above. The only local state is the current row selection, which is a
 * client concern until a bulk action is actually run.
 */
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
    setLayout,
    setTab,
    clearFilters,
} = useResource(
    () => props.resource,
    () => props.state,
);

const {
    pending,
    processing,
    formUrl,
    formContext,
    runRecord,
    runBulk,
    reorder,
    editCell,
    runTable,
    confirm,
    cancel,
} = useActions(
    () => props.resource.slug,
    () => props.actionEndpoints,
    () => props.resource.parentKey,
);

const selected = ref<Array<string | number>>([]);

/**
 * Either renderer, held through one ref.
 *
 * Both expose `clearSelection` and `clearColumnSearches` — the grid's second
 * one deliberately doing nothing — so everything below stays unaware of which
 * layout is mounted. The union is written out because that is what makes
 * TypeScript check the pair rather than infer one of them.
 */
const tableRef = ref<
    InstanceType<typeof DataTable> | InstanceType<typeof DataTableGrid> | null
>(null);

function onSelectionChange(keys: Array<string | number>): void {
    selected.value = keys;
}

function onRunAction(action: ActionDefinition, record: string | number): void {
    runRecord(action, record);
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

/**
 * Switching layout drops the selection.
 *
 * `useResource` visits with `preserveState`, so this page is *not* remounted
 * when the layout changes — but the renderer under it is destroyed and rebuilt
 * with an empty selection model. Without this, `selected` survives the switch
 * and the bulk action bar claims N records are selected while nothing on
 * screen is ticked.
 */
watch(
    () => props.state.layout,
    () => {
        selected.value = [];
    },
);

/**
 * The page's own header actions plus whatever the table declared. Both are
 * server-resolved and already authorized, so this only concatenates them.
 */
const headerActions = computed(() => [
    ...(props.page.headerActions as ActionDefinition[]),
    ...props.table.headerActions,
]);
</script>

<template>
    <Head :title="page.title" />

    <div class="flex flex-col gap-4">
        <PageHeader :heading="page.heading" :subheading="page.subheading">
            <template #actions>
                <ActionButton
                    v-for="action in headerActions"
                    :key="action.name"
                    :action="action"
                    size="default"
                    @run="runTable"
                />
            </template>
        </PageHeader>

        <PageWidgets :widgets="headerWidgets" :widget-data="widgetData" />

        <!--
            Standing context, then notices, then the controls. A reader meets
            what the list is and what is unusual about it today before the
            things that change what they are looking at.
        -->
        <DataTableIntro
            :description="table.description"
            :callouts="table.callouts"
        />

        <!--
            Tabs, controls, rows and paging are one object, so they are drawn
            as one: a single bordered surface divided by rules rather than four
            floating blocks separated by gutters.

            The old layout gave all four the same 24px gap, which said they
            were four equally-related things — and cost about 120px of nothing
            above the first row. On an ERP screen, where the job of the page is
            to show as many rows as will fit and make it obvious which controls
            act on them, both halves of that are wrong.
        -->
        <section
            class="panel-data-panel flex flex-col overflow-hidden rounded-lg border bg-card"
        >
            <DataTableTabs :tabs="tabs" @select="setTab" />

            <div class="border-b px-3 py-2.5">
                <DataTableToolbar
                    :table="table"
                    :state="state"
                    @search="setSearch"
                    @filter="setFilter"
                    @filters="setFilters"
                    @run-action="runTable"
                    @sort="setSort"
                    @clear="clearTableFilters"
                >
                    <template #actions>
                        <DataTableLayoutToggle
                            :layouts="table.layouts"
                            :layout="state.layout"
                            @select="setLayout"
                        />
                        <DataTableColumnManager
                            :table="table"
                            :visible="state.columns.visible"
                            :order="state.columns.order"
                            @change="setColumns"
                            @reset="resetColumns"
                        />
                    </template>
                </DataTableToolbar>
            </div>

            <!--
                One schema, two renderers. Which one is drawn is the server's
                answer in `state.layout`, already checked against the layouts
                the table offers — so a table that declared no card face never
                reaches the grid however the URL is written.

                Borderless: the surface around it is already the frame.
            -->
            <DataTable
                v-if="state.layout === 'table'"
                ref="tableRef"
                :table="table"
                :rows="rows"
                :state="state"
                :summaries="summaries"
                :group-summaries="groupSummaries"
                :bordered="false"
                @sort="setSort"
                @column-search="setColumnSearch"
                @selection-change="onSelectionChange"
                @run-action="onRunAction"
                @edit-cell="editCell"
                @run-table-action="runTable"
                @reorder="reorder"
            />

            <DataTableGrid
                v-else
                ref="tableRef"
                class="p-3"
                :table="table"
                :rows="rows"
                :state="state"
                :summaries="summaries"
                :group-summaries="groupSummaries"
                @selection-change="onSelectionChange"
                @run-action="onRunAction"
                @edit-cell="editCell"
                @run-table-action="runTable"
            />

            <!--
                The panel's footer. Muted, so the eye reads it as chrome
                belonging to the rows above rather than as another control
                block of its own.
            -->
            <div class="border-t bg-muted/30 px-3 py-2">
                <DataTablePagination
                    :pagination="pagination"
                    :per-page-options="table.perPageOptions"
                    @page="setPage"
                    @per-page="setPerPage"
                />
            </div>
        </section>

        <!--
            After the table in the document, so the tab order runs
            controls → rows → actions-on-those-rows. It is positioned over the
            page rather than in the flow, so selecting a row moves nothing.
        -->
        <DataTableBulkActions
            :actions="table.bulkActions"
            :selected="selected"
            :processing="processing"
            @run="onRunBulk"
            @clear="clearSelection"
        />

        <ActionModal
            :action="pending?.action ?? null"
            :processing="processing"
            :form-url="formUrl"
            :context="formContext"
            @confirm="confirm"
            @cancel="cancel"
            @saved="cancel"
            @run="runTable"
        />

        <PageWidgets :widgets="footerWidgets" :widget-data="widgetData" />
    </div>
</template>
