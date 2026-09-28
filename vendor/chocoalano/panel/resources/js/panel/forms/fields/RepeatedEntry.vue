<script setup lang="ts">
import { provideFieldScope } from '@/panel/forms/fieldIdentity';
import FormComponentRenderer from '@/panel/forms/FormComponentRenderer.vue';
import type {
    FormComponentDefinition,
    FormValue,
    FormValues,
} from '@/panel/types/form';

/**
 * One entry of a repeater or a builder.
 *
 * It exists to be a component rather than a `v-for` block, because `provide`
 * is per-instance: the scope below has to be different for each entry, and a
 * loop inside one component would provide once for all of them.
 *
 * The scope carries both halves of a field's identity — see `fieldIdentity`.
 * The DOM half makes this entry's controls unique so a label points at its own
 * input; the path half is the prefix the server already keys errors by, so the
 * form can still find the control an error belongs to.
 */
const props = defineProps<{
    schema: FormComponentDefinition[];
    values: FormValues;
    errors: Record<string, string>;
    /** Unique for the lifetime of this entry, and stable across reordering. */
    domScope: string;
    /** `items.0.` — what the server calls this entry. */
    pathScope: string;
}>();

const emit = defineEmits<{ change: [name: string, value: FormValue] }>();

provideFieldScope(() => ({ dom: props.domScope, path: props.pathScope }));
</script>

<template>
    <FormComponentRenderer
        v-for="(node, position) in schema"
        :key="position"
        :node="node"
        :values="values"
        :errors="errors"
        @change="(name, value) => emit('change', name, value)"
    />
</template>
