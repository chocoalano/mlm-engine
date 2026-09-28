<script setup lang="ts">
import { Input } from '@/components/ui/input';
import FieldWrapper from '@/panel/forms/fields/FieldWrapper.vue';
import type { TextFieldDefinition } from '@/panel/types/form';

defineProps<{
    field: TextFieldDefinition;
    modelValue: unknown;
    error?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
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
        <Input
            :id="controlId"
            :aria-describedby="describedBy"
            :type="field.inputType"
            :model-value="typeof modelValue === 'string' ? modelValue : ''"
            :placeholder="field.placeholder ?? undefined"
            :disabled="field.disabled"
            :maxlength="field.maxLength ?? undefined"
            :aria-invalid="invalid"
            @update:model-value="
                (value) => emit('update:modelValue', String(value))
            "
        />
    </FieldWrapper>
</template>
