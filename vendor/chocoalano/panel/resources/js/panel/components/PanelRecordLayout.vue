<script setup lang="ts">
import { computed } from 'vue';
import PanelSubNavigation from '@/panel/components/PanelSubNavigation.vue';
import type { PageSubNavigation } from '@/panel/types/page';

/**
 * Arranges a record page around its sub-navigation.
 *
 * Shared by the view and edit pages so the position is honoured in one place
 * rather than twice. With no items it renders the page exactly as it would
 * have been, so a resource with a single record page pays nothing for this.
 */
const props = defineProps<{
    subNavigation: PageSubNavigation;
}>();

const hasItems = computed(() => props.subNavigation.items.length > 0);
const isSide = computed(() => props.subNavigation.position !== 'top');
</script>

<template>
    <div class="flex flex-col gap-4">
        <slot name="header" />

        <template v-if="!hasItems">
            <slot />
        </template>

        <template v-else-if="!isSide">
            <PanelSubNavigation
                :items="subNavigation.items"
                :position="subNavigation.position"
            />
            <slot />
        </template>

        <div
            v-else
            class="flex flex-col gap-6 lg:flex-row"
            :class="
                subNavigation.position === 'end' ? 'lg:flex-row-reverse' : ''
            "
        >
            <PanelSubNavigation
                class="lg:w-44 lg:shrink-0"
                :items="subNavigation.items"
                :position="subNavigation.position"
            />
            <div class="min-w-0 flex-1">
                <slot />
            </div>
        </div>
    </div>
</template>
