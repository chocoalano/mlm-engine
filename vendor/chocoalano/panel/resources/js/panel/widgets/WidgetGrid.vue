<script setup lang="ts">
import type {
    Breakpoint,
    ColumnSpan,
    SpanValue,
    WidgetData,
    WidgetDefinition,
} from '@/panel/types/widget';
import WidgetRenderer from '@/panel/widgets/WidgetRenderer.vue';

withDefaults(
    defineProps<{
        widgets: WidgetDefinition[];
        /** Deferred: absent from the first response, so optional. */
        widgetData?: WidgetData | null;
        /** The props a polling widget reloads. */
        reloadProps?: string[];
    }>(),
    {
        widgetData: null,
        reloadProps: () => ['widgets', 'widgetData'],
    },
);

/**
 * Every span class is written out in full.
 *
 * Building them by interpolation would make them invisible to the Tailwind
 * compiler, so the classes would simply not exist in the bundle and every
 * widget would silently be one column wide.
 */
const SPAN_CLASSES: Record<Breakpoint, Record<SpanValue, string>> = {
    default: {
        1: 'col-span-1',
        2: 'col-span-2',
        3: 'col-span-3',
        4: 'col-span-4',
        full: 'col-span-full',
    },
    md: {
        1: 'md:col-span-1',
        2: 'md:col-span-2',
        3: 'md:col-span-3',
        4: 'md:col-span-4',
        full: 'md:col-span-full',
    },
    lg: {
        1: 'lg:col-span-1',
        2: 'lg:col-span-2',
        3: 'lg:col-span-3',
        4: 'lg:col-span-4',
        full: 'lg:col-span-full',
    },
    xl: {
        1: 'xl:col-span-1',
        2: 'xl:col-span-2',
        3: 'xl:col-span-3',
        4: 'xl:col-span-4',
        full: 'xl:col-span-full',
    },
};

const BREAKPOINTS: Breakpoint[] = ['default', 'md', 'lg', 'xl'];

function spanClasses(span: ColumnSpan): string {
    return BREAKPOINTS.map(
        (breakpoint) =>
            SPAN_CLASSES[breakpoint][span[breakpoint]] ??
            SPAN_CLASSES[breakpoint][1],
    ).join(' ');
}
</script>

<template>
    <div
        class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
    >
        <div
            v-for="widget in widgets"
            :key="widget.id"
            :class="spanClasses(widget.columnSpan)"
        >
            <WidgetRenderer
                :widget="widget"
                :resolved="widgetData"
                :reload-props="reloadProps"
            />
        </div>
    </div>
</template>
