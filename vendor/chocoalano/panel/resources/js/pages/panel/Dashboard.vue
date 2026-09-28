<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import DashboardGuide from '@/panel/components/DashboardGuide.vue';
import PageHeader from '@/panel/components/PageHeader.vue';
import type { FormDefinition } from '@/panel/types/form';
import type { PageMetadata } from '@/panel/types/page';
import type { WidgetData, WidgetDefinition } from '@/panel/types/widget';
import WidgetFilters from '@/panel/widgets/WidgetFilters.vue';
import WidgetGrid from '@/panel/widgets/WidgetGrid.vue';
import PanelLayout from '@/panel/layouts/PanelLayout.vue';

defineOptions({ layout: PanelLayout });

/**
 * `widgetData` is deferred: the prop is absent from the first response and
 * arrives in a follow-up request, so it is optional rather than required.
 *
 * `filters` is the page's own — one set of controls every widget on it reads.
 * Null for a dashboard that declares none, which is most of them.
 */
withDefaults(
    defineProps<{
        page: PageMetadata;
        widgets: WidgetDefinition[];
        widgetData?: WidgetData | null;
        filters?: { form: FormDefinition } | null;
    }>(),
    {
        widgetData: null,
        filters: null,
    },
);
</script>

<template>
    <Head :title="page.title" />

    <div class="flex flex-col gap-6">
        <PageHeader :heading="page.heading" :subheading="page.subheading" />

        <WidgetFilters
            v-if="filters"
            :form="filters.form"
            namespace="filters"
        />

        <WidgetGrid
            v-if="widgets.length > 0"
            :widgets="widgets"
            :widget-data="widgetData"
            :reload-props="['widgets', 'widgetData']"
        />

        <!--
            Not an `EmptyState`: that component states a fact and offers a
            slot, which is right for a filtered table and wrong for the first
            screen of a new panel. See `DashboardGuide` for what this says
            instead.
        -->
        <DashboardGuide v-else />
    </div>
</template>
