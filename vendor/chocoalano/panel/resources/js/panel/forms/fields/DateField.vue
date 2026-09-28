<script setup lang="ts">
import PanelDatePicker from '@/panel/components/PanelDatePicker.vue';
import FieldWrapper from '@/panel/forms/fields/FieldWrapper.vue';
import type { DateFieldDefinition } from '@/panel/types/form';

defineProps<{
    field: DateFieldDefinition;
    modelValue: unknown;
    error?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>();
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
        <PanelDatePicker
            :id="controlId"
            :described-by="describedBy"
            class="w-full"
            :model-value="typeof modelValue === 'string' ? modelValue : null"
            :disabled="field.disabled"
            :min="field.minDate"
            :max="field.maxDate"
            :invalid="invalid === true"
            :aria-label="field.label"
            :clearable="!field.required"
            @update:model-value="(value) => emit('update:modelValue', value)"
        />
    </FieldWrapper>
</template>
