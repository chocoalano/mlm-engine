<script setup lang="ts">
import { computed } from 'vue';
import FormComponentRenderer from '@/panel/forms/FormComponentRenderer.vue';
import { gridClass, spanClass } from '@/panel/lib/grid';
import type { FormValue, FormValues, GridDefinition } from '@/panel/types/form';

const props = defineProps<{
    grid: GridDefinition;
    values: FormValues;
    errors: Record<string, string>;
}>();

const emit = defineEmits<{ change: [name: string, value: FormValue] }>();

const gridClasses = computed(() => gridClass(props.grid.columns));

/**
 * A layout takes the whole row; a field takes what it asked for.
 *
 * Both are resolved against the container rather than on the server, because
 * the container is the only thing that knows how wide it is at any given
 * breakpoint — see `panel/lib/grid`.
 */
function nodeSpanClass(node: {
    component: string;
    columnSpan?: number | 'full';
}): string {
    return spanClass(
        node.component === 'field' ? (node.columnSpan ?? 1) : 'full',
        props.grid.columns,
    );
}
</script>

<template>
    <div class="grid gap-4" :class="gridClasses">
        <div
            v-for="(node, index) in grid.schema"
            :key="index"
            :class="nodeSpanClass(node)"
        >
            <FormComponentRenderer
                :node="node"
                :values="values"
                :errors="errors"
                @change="(name, value) => emit('change', name, value)"
            />
        </div>
    </div>
</template>
