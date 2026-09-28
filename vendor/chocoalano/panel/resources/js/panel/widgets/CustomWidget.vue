<script setup lang="ts">
import { computed, defineAsyncComponent } from 'vue';
import { resolveWidgetComponent } from '@/panel/widgets/registry';
import WidgetFallback from '@/panel/widgets/WidgetFallback.vue';

const props = defineProps<{
    component: string;
    data: Record<string, unknown>;
}>();

/**
 * Resolved through the build-time registry only. An unknown name renders the
 * neutral fallback instead of throwing.
 */
const resolved = computed(() => {
    const loader = resolveWidgetComponent(props.component);

    return loader ? defineAsyncComponent(loader) : null;
});
</script>

<template>
    <component :is="resolved" v-if="resolved" v-bind="data" />
    <WidgetFallback v-else :component="component" />
</template>
