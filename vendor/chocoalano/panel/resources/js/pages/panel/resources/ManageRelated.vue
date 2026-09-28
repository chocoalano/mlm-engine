<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PageHeader from '@/panel/components/PageHeader.vue';
import PanelRecordLayout from '@/panel/components/PanelRecordLayout.vue';
import RelationManagerPanel from '@/panel/relations/RelationManagerPanel.vue';
import type { PageMetadata } from '@/panel/types/page';
import type { RelationDefinition } from '@/panel/types/relation';
import type { ResourceMeta } from '@/panel/types/table';
import type { WidgetData, WidgetDefinition } from '@/panel/types/widget';
import PageWidgets from '@/panel/widgets/PageWidgets.vue';
import PanelLayout from '@/panel/layouts/PanelLayout.vue';

defineOptions({ layout: PanelLayout });

/**
 * A page devoted to one of a record's relations.
 *
 * The same component the record pages render inline, given the whole page.
 * That is the only difference between the two: a relation table's behaviour
 * does not depend on where it is shown.
 */
withDefaults(
    defineProps<{
        page: PageMetadata;
        resource: ResourceMeta;
        recordKey: string | number;
        relation: RelationDefinition;
        /** Placed by the page above and below its own content. */
        headerWidgets?: WidgetDefinition[];
        footerWidgets?: WidgetDefinition[];
        /** Deferred: absent from the first response, so optional. */
        widgetData?: WidgetData | null;
    }>(),
    {
        headerWidgets: () => [],
        footerWidgets: () => [],
        widgetData: null,
    },
);
</script>

<template>
    <Head :title="page.title" />

    <PanelRecordLayout :sub-navigation="page.subNavigation">
        <template #header>
            <PageHeader :heading="page.heading" :subheading="page.subheading" />
        </template>

        <PageWidgets :widgets="headerWidgets" :widget-data="widgetData" />

        <RelationManagerPanel
            :relation="relation"
            :resource="resource.slug"
            :record="recordKey"
        />

        <PageWidgets :widgets="footerWidgets" :widget-data="widgetData" />
    </PanelRecordLayout>
</template>
