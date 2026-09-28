<script setup lang="ts">
import { Checkbox } from '@/components/ui/checkbox';
import FieldWrapper from '@/panel/forms/fields/FieldWrapper.vue';
import type { CheckboxFieldDefinition } from '@/panel/types/form';

defineProps<{
    field: CheckboxFieldDefinition;
    modelValue: unknown;
    error?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>();
</script>

<template>
    <FieldWrapper
        v-slot="{ controlId, describedBy, invalid }"
        inline
        :name="field.name"
        :inline-label="field.inlineLabel"
        :label="field.label"
        :required="field.required"
        :helper-text="field.helperText"
        :error="error"
    >
        <Checkbox
            :id="controlId"
            :aria-describedby="describedBy"
            :aria-invalid="invalid"
            :model-value="modelValue === true"
            :disabled="field.disabled"
            @update:model-value="
                (value) => emit('update:modelValue', value === true)
            "
        />
    </FieldWrapper>
</template>
