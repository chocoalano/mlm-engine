<script setup lang="ts">
import { computed, defineAsyncComponent } from 'vue';
import FieldWrapper from '@/panel/forms/fields/FieldWrapper.vue';
import { resolveFormComponent } from '@/panel/forms/registry';
import type { CustomFieldDefinition, FormValue } from '@/panel/types/form';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

const props = defineProps<{
    field: CustomFieldDefinition;
    modelValue: unknown;
    error?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: FormValue] }>();

/**
 * Loaded on demand: a custom field is rare, and bundling every one of them
 * into the panel's main chunk would cost every page that has none.
 *
 * The name resolves through the build-time registry and nothing else. An
 * unknown one renders a placeholder rather than throwing, so a mistyped
 * component cannot take the rest of the form down with it.
 */
const component = computed(() => {
    const loader = resolveFormComponent(props.field.componentName);

    return loader === null ? null : defineAsyncComponent(loader);
});
</script>

<template>
    <FieldWrapper
        v-slot="{ controlId, describedBy, invalid }"
        :name="field.name"
        :inline-label="field.inlineLabel"
        :label="field.label"
        :required="field.required"
        :helper-text="field.helperText"
        :error="error"
    >
        <!--
            The identity is passed on rather than applied here: this component
            does not know what the host renders, so it cannot know which
            element is the control. A registered component that binds
            `controlId` and `describedBy` to its own input gets the same
            label and description association every built-in field has; one
            that ignores them is no worse off than before.
        -->
        <component
            :is="component"
            v-if="component"
            :field="field"
            :model-value="modelValue"
            :config="field.config"
            :error="error"
            :disabled="field.disabled"
            :control-id="controlId"
            :described-by="describedBy"
            :invalid="invalid"
            @update:model-value="
                (value: FormValue) => emit('update:modelValue', value)
            "
        />
        <p v-else class="text-sm text-muted-foreground">
            {{ t('forms.no_renderer') }}
        </p>
    </FieldWrapper>
</template>
