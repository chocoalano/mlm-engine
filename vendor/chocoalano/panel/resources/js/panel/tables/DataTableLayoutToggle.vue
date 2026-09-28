<script setup lang="ts">
import { LayoutGrid, Rows3 } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import type { TableLayout } from '@/panel/types/table';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

/**
 * Switches between the table's renderers.
 *
 * Renders **nothing** when the table offers only one layout, which is every
 * table that has not declared a card face — that single guard is what keeps a
 * feature nobody opted into from changing a single pixel of an existing panel.
 *
 * The list comes from the server already resolved, so this never reasons about
 * *why* a layout is missing: a reorderable table is simply one that offers the
 * table alone.
 */
const props = defineProps<{
    layouts: TableLayout[];
    layout: TableLayout;
}>();

const emit = defineEmits<{ select: [layout: TableLayout] }>();

const options: Array<{
    value: TableLayout;
    key: string;
    icon: typeof Rows3;
}> = [
    { value: 'table', key: 'tables.layout_table', icon: Rows3 },
    { value: 'grid', key: 'tables.layout_cards', icon: LayoutGrid },
];

const available = computed(() =>
    options.filter((option) => props.layouts.includes(option.value)),
);
</script>

<template>
    <div
        v-if="available.length > 1"
        class="flex items-center rounded-md border p-0.5"
        role="group"
        :aria-label="t('tables.layout')"
    >
        <Button
            v-for="option in available"
            :key="option.value"
            type="button"
            variant="ghost"
            size="icon-sm"
            :aria-label="t(option.key)"
            :aria-pressed="layout === option.value"
            :class="
                layout === option.value
                    ? 'bg-accent text-accent-foreground'
                    : 'text-muted-foreground'
            "
            @click="emit('select', option.value)"
        >
            <component :is="option.icon" />
        </Button>
    </div>
</template>
