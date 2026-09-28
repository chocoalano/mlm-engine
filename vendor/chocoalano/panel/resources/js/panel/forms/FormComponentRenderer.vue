<script setup lang="ts">
import { computed } from 'vue';
import { matchesConditions } from '@/panel/forms/conditions';
import FormCallout from '@/panel/forms/FormCallout.vue';
import FormCustomComponent from '@/panel/forms/FormCustomComponent.vue';
import FormEmptyState from '@/panel/forms/FormEmptyState.vue';
import FormField from '@/panel/forms/FormField.vue';
import FormGrid from '@/panel/forms/FormGrid.vue';
import FormPrime from '@/panel/forms/FormPrime.vue';
import FormRelationship from '@/panel/forms/FormRelationship.vue';
import FormSection from '@/panel/forms/FormSection.vue';
import FormTabs from '@/panel/forms/FormTabs.vue';
import type {
    FormComponentDefinition,
    FormValue,
    FormValues,
} from '@/panel/types/form';

/**
 * Renders one schema node. Layouts recurse back through here, so nesting
 * depth is a data concern rather than a component concern.
 */
const props = defineProps<{
    node: FormComponentDefinition;
    values: FormValues;
    errors: Record<string, string>;
}>();

const emit = defineEmits<{ change: [name: string, value: FormValue] }>();

/**
 * A field whose conditions no longer hold is not rendered at all.
 *
 * The same conditions decide what the server validates and dehydrates, so
 * this is not a cosmetic hide: a field that is not on screen is a field that
 * is not part of this form, on both sides.
 */
const shown = computed(
    () =>
        props.node.component !== 'field' ||
        matchesConditions(props.node.conditions, props.values),
);
</script>

<template>
    <template v-if="!shown" />

    <FormSection
        v-else-if="node.component === 'section'"
        :section="node"
        :values="values"
        :errors="errors"
        @change="(name, value) => emit('change', name, value)"
    />

    <FormGrid
        v-else-if="node.component === 'grid'"
        :grid="node"
        :values="values"
        :errors="errors"
        @change="(name, value) => emit('change', name, value)"
    />

    <FormRelationship
        v-else-if="node.component === 'relationship'"
        :group="node"
        :values="values"
        :errors="errors"
        @change="(name, value) => emit('change', name, value)"
    />

    <FormTabs
        v-else-if="node.component === 'tabs'"
        :tabs="node"
        :values="values"
        :errors="errors"
        @change="(name, value) => emit('change', name, value)"
    />

    <FormCallout
        v-else-if="node.component === 'callout'"
        :callout="node"
        :values="values"
        :errors="errors"
        @change="(name, value) => emit('change', name, value)"
    />

    <FormEmptyState
        v-else-if="node.component === 'empty-state'"
        :state="node"
    />

    <FormCustomComponent
        v-else-if="node.component === 'custom'"
        :node="node"
        :values="values"
        :errors="errors"
        @change="(name, value) => emit('change', name, value)"
    />

    <FormPrime
        v-else-if="
            node.component === 'prime-text' ||
            node.component === 'prime-icon' ||
            node.component === 'prime-image'
        "
        :node="node"
    />

    <!--
        A wizard is rendered by FormRenderer, which owns the submit controls.
        Reaching one here would mean a nested wizard, which the schema does
        not describe, so it renders nothing rather than falling through to
        the field renderer.
    -->
    <template v-else-if="node.component === 'wizard'" />

    <FormField
        v-else
        :field="node"
        :values="values"
        :errors="errors"
        @change="(name, value) => emit('change', name, value)"
    />
</template>
