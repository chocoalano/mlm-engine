<script setup lang="ts">
import type { WidgetData, WidgetDefinition } from '@/panel/types/widget';
import WidgetGrid from '@/panel/widgets/WidgetGrid.vue';

/**
 * Widgets a resource page places above or below its own content.
 *
 * Renders nothing when there are none, which is the common case, so a page
 * that never declares any pays no markup for the possibility.
 */
withDefaults(
    defineProps<{
        widgets?: WidgetDefinition[];
        /** Deferred: absent from the first response, so optional. */
        widgetData?: WidgetData | null;
        /** The props a polling widget reloads. */
        reloadProps?: string[];
    }>(),
    {
        widgets: () => [],
        widgetData: null,
        reloadProps: () => ['headerWidgets', 'footerWidgets', 'widgetData'],
    },
);
</script>

<template>
    <WidgetGrid
        v-if="widgets.length > 0"
        :widgets="widgets"
        :widget-data="widgetData"
        :reload-props="reloadProps"
    />
</template>
