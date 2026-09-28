<script setup lang="ts">
import { computed, defineAsyncComponent } from 'vue';
import FormComponentRenderer from '@/panel/forms/FormComponentRenderer.vue';
import { resolveFormComponent } from '@/panel/forms/registry';
import type {
    CustomComponentDefinition,
    FormValue,
    FormValues,
} from '@/panel/types/form';

const props = defineProps<{
    node: CustomComponentDefinition;
    values: FormValues;
    errors: Record<string, string>;
}>();

const emit = defineEmits<{ change: [name: string, value: FormValue] }>();

/**
 * A bespoke wrapper that may still hold ordinary fields.
 *
 * Its children are rendered here and passed in as a slot, so the custom
 * component decides where they go without having to know how to render any
 * of them. A component that ignores the slot is a component with no fields
 * in it, which is a legitimate thing to be.
 */
const component = computed(() => {
    const loader = resolveFormComponent(props.node.componentName);

    return loader === null ? null : defineAsyncComponent(loader);
});
</script>

<template>
    <component :is="component" v-if="component" :config="node.config">
        <FormComponentRenderer
            v-for="(child, index) in node.schema"
            :key="index"
            :node="child"
            :values="values"
            :errors="errors"
            @change="(name, value) => emit('change', name, value)"
        />
    </component>

    <!--
        An unregistered name still renders its children. The wrapper is
        decoration; the fields inside it are the form, and losing them because
        a component was renamed would be a far worse failure than losing the
        frame around them.
    -->
    <div v-else class="flex flex-col gap-4">
        <FormComponentRenderer
            v-for="(child, index) in node.schema"
            :key="index"
            :node="child"
            :values="values"
            :errors="errors"
            @change="(name, value) => emit('change', name, value)"
        />
    </div>
</template>
