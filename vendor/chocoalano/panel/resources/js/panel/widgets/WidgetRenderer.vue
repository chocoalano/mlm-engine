<script setup lang="ts">
import { computed } from 'vue';
import LoadingState from '@/panel/components/LoadingState.vue';
import type { WidgetData, WidgetDefinition } from '@/panel/types/widget';
import {
    asChart,
    asCustomData,
    asStats,
    asTable,
} from '@/panel/types/widgetGuards';
import ChartWidget from '@/panel/widgets/ChartWidget.vue';
import CustomWidget from '@/panel/widgets/CustomWidget.vue';
import StatsWidget from '@/panel/widgets/StatsWidget.vue';
import TableWidget from '@/panel/widgets/TableWidget.vue';
import WidgetShell from '@/panel/widgets/WidgetShell.vue';

const props = withDefaults(
    defineProps<{
        widget: WidgetDefinition;
        /** Deferred payloads keyed by widget id; absent until they arrive. */
        resolved?: WidgetData | null;
        /** The props a poll reloads. The page knows them; a widget does not. */
        reloadProps?: string[];
    }>(),
    {
        resolved: null,
        reloadProps: () => ['widgets', 'widgetData'],
    },
);

function assertNever(value: never): never {
    throw new Error(`Unhandled widget type: ${JSON.stringify(value)}`);
}

/**
 * Exhaustive by construction: adding a PHP widget type without a renderer
 * here is a compile error rather than an empty card.
 */
function ensureHandled(widget: WidgetDefinition): void {
    switch (widget.type) {
        case 'stats':
        case 'table':
        case 'chart':
        case 'custom':
            return;
        default:
            assertNever(widget);
    }
}

ensureHandled(props.widget);

/**
 * Eager widgets carry their data inline; lazy ones fill in from the deferred
 * prop once it lands, and show a skeleton until then.
 */
const payload = computed<unknown>(
    () => props.widget.data ?? props.resolved?.[props.widget.id] ?? null,
);

const stats = computed(() => asStats(payload.value));
const table = computed(() => asTable(payload.value));
const chart = computed(() => asChart(payload.value));
const custom = computed(() => asCustomData(payload.value));
</script>

<template>
    <!-- The heading, the filters, and the refresh belong to every widget, so
         they live in one component rather than four. -->
    <WidgetShell :widget="widget" :reload-props="reloadProps">
        <template v-if="widget.type === 'stats'">
            <StatsWidget v-if="stats" :stats="stats" />
            <LoadingState v-else variant="cards" :count="3" />
        </template>

        <template v-else-if="widget.type === 'table'">
            <TableWidget
                v-if="table"
                :columns="table.columns"
                :rows="table.rows"
                :empty-message="table.emptyMessage"
                :state="table.state"
                :pagination="table.pagination"
                :namespace="table.namespace"
                :searchable="table.searchable"
            />
            <LoadingState v-else variant="rows" :count="5" />
        </template>

        <template v-else-if="widget.type === 'chart'">
            <ChartWidget
                v-if="chart"
                :variant="chart.variant"
                :labels="chart.labels"
                :series="chart.series"
                :options="chart.options"
                :max-height="chart.maxHeight"
            />
            <LoadingState v-else variant="rows" :count="4" />
        </template>

        <template v-else>
            <CustomWidget
                v-if="custom"
                :component="widget.component"
                :data="custom"
            />
            <LoadingState v-else variant="rows" :count="3" />
        </template>
    </WidgetShell>
</template>
