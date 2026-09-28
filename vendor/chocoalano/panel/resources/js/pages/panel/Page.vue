<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ActionButton from '@/panel/actions/ActionButton.vue';
import PageHeader from '@/panel/components/PageHeader.vue';
import type { ActionDefinition } from '@/panel/types/action';
import type { PageMetadata } from '@/panel/types/page';
import type { WidgetData, WidgetDefinition } from '@/panel/types/widget';
import WidgetGrid from '@/panel/widgets/WidgetGrid.vue';
import PanelLayout from '@/panel/layouts/PanelLayout.vue';

defineOptions({ layout: PanelLayout });

/**
 * The generic renderer for a standalone page.
 *
 * A page with nothing bespoke to draw uses this and needs no Vue file of its
 * own; one that does declares its own `$component` instead.
 */
const props = withDefaults(
    defineProps<{
        page: PageMetadata;
        widgets: WidgetDefinition[];
        /** Deferred: absent from the first response, so optional. */
        widgetData?: WidgetData | null;
    }>(),
    {
        widgetData: null,
    },
);

const headerActions = props.page.headerActions as ActionDefinition[];
</script>

<template>
    <Head :title="page.title" />

    <div class="flex flex-col gap-6">
        <PageHeader :heading="page.heading" :subheading="page.subheading">
            <template #actions>
                <ActionButton
                    v-for="action in headerActions"
                    :key="action.name"
                    :action="action"
                    size="default"
                />
            </template>
        </PageHeader>

        <WidgetGrid
            v-if="widgets.length > 0"
            :widgets="widgets"
            :widget-data="widgetData"
        />

        <slot />
    </div>
</template>
