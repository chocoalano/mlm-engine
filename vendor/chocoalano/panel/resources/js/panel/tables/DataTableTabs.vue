<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { resolveIcon } from '@/panel/icons/registry';
import type { TableTab } from '@/panel/types/table';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

/**
 * Filter tabs above a list page.
 *
 * A tab is a URL, not local state: pressing one navigates, and the server
 * decides which is active. That is what keeps back, forward, refresh, and
 * bookmark behaving.
 */
defineProps<{
    tabs: TableTab[];
}>();

const emit = defineEmits<{
    (event: 'select', key: string): void;
}>();
</script>

<template>
    <nav
        v-if="tabs.length > 0"
        :aria-label="t('tables.filter')"
        class="flex items-center gap-1 overflow-x-auto border-b px-3"
    >
        <button
            v-for="tab in tabs"
            :key="tab.key"
            type="button"
            :aria-current="tab.active ? 'page' : undefined"
            class="-mb-px flex items-center gap-2 rounded-md rounded-b-none border-b-2 px-3 py-2 text-sm transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            :class="
                tab.active
                    ? 'border-primary font-medium text-foreground'
                    : 'border-transparent text-muted-foreground'
            "
            @click="emit('select', tab.key)"
        >
            <component
                :is="resolveIcon(tab.icon)"
                v-if="resolveIcon(tab.icon)"
                class="size-4"
            />
            <span>{{ tab.label }}</span>
            <Badge
                v-if="tab.badge !== null && tab.badge !== ''"
                variant="secondary"
                class="tabular-nums"
            >
                {{ tab.badge }}
            </Badge>
        </button>
    </nav>
</template>
