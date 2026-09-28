<script setup lang="ts">
import PasswordInput from '@/components/PasswordInput.vue';
import FieldWrapper from '@/panel/forms/fields/FieldWrapper.vue';
import type { PasswordFieldDefinition } from '@/panel/types/form';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

/**
 * A confirmed password renders its confirmation input here rather than as a
 * separate schema field. The backend adds the `confirmed` rule and the
 * matching `{name}_confirmation` rule, so the pair stays in step without the
 * form having to declare it twice.
 */
defineProps<{
    field: PasswordFieldDefinition;
    modelValue: unknown;
    confirmationValue: unknown;
    error?: string;
    confirmationError?: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
    'update:confirmationValue': [value: string];
}>();
</script>

<template>
    <div class="flex flex-col gap-4">
        <FieldWrapper
            v-slot="{ controlId, describedBy, invalid }"
            :name="field.name"
            :inline-label="field.inlineLabel"
            :label="field.label"
            :required="field.required"
            :helper-text="field.helperText"
            :error="error"
        >
            <PasswordInput
                :id="controlId"
                :aria-describedby="describedBy"
                :model-value="typeof modelValue === 'string' ? modelValue : ''"
                :placeholder="field.placeholder ?? undefined"
                :disabled="field.disabled"
                autocomplete="new-password"
                :aria-invalid="invalid"
                @update:model-value="
                    (value: string | number) =>
                        emit('update:modelValue', String(value))
                "
            />
        </FieldWrapper>

        <FieldWrapper
            v-if="field.confirmed"
            v-slot="{ controlId, describedBy, invalid }"
            :name="`${field.name}_confirmation`"
            :label="t('forms.confirm_field', { field: field.label })"
            :required="field.required"
            :helper-text="null"
            :error="confirmationError"
        >
            <PasswordInput
                :id="controlId"
                :aria-describedby="describedBy"
                :aria-invalid="invalid"
                :model-value="
                    typeof confirmationValue === 'string'
                        ? confirmationValue
                        : ''
                "
                :disabled="field.disabled"
                autocomplete="new-password"
                @update:model-value="
                    (value: string | number) =>
                        emit('update:confirmationValue', String(value))
                "
            />
        </FieldWrapper>
    </div>
</template>
