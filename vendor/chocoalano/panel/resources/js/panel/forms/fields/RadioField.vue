<script setup lang="ts">
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import FieldWrapper from '@/panel/forms/fields/FieldWrapper.vue';
import type { RadioFieldDefinition } from '@/panel/types/form';

const props = defineProps<{
    field: RadioFieldDefinition;
    modelValue: unknown;
    error?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

/**
 * Option values are strings on the wire, so the selection is compared as one
 * — a key that is `1` in the database and `'1'` in the form is the same
 * option, not two.
 */
function selected(): string {
    return props.modelValue === null || props.modelValue === undefined
        ? ''
        : String(props.modelValue);
}
</script>

<template>
    <FieldWrapper
        v-slot="{ controlId, describedBy, invalid, labelledBy }"
        :name="field.name"
        :inline-label="field.inlineLabel"
        :label="field.label"
        :required="field.required"
        :helper-text="field.helperText"
        :error="error"
        group
    >
        <RadioGroup
            :id="controlId"
            :aria-labelledby="labelledBy"
            :aria-describedby="describedBy"
            :model-value="selected()"
            :disabled="field.disabled"
            :class="field.inline ? 'flex flex-wrap gap-x-6 gap-y-3' : 'gap-3'"
            :aria-invalid="invalid"
            @update:model-value="
                (value) => emit('update:modelValue', String(value ?? ''))
            "
        >
            <div
                v-for="option in field.options"
                :key="option.value"
                class="flex items-start gap-2"
            >
                <RadioGroupItem
                    :id="`${controlId}-${option.value}`"
                    :value="option.value"
                    class="mt-0.5"
                />
                <div class="flex flex-col gap-0.5">
                    <Label
                        :for="`${controlId}-${option.value}`"
                        class="font-normal"
                    >
                        {{ option.label }}
                    </Label>
                    <p
                        v-if="option.description"
                        class="text-xs text-muted-foreground"
                    >
                        {{ option.description }}
                    </p>
                </div>
            </div>
        </RadioGroup>
    </FieldWrapper>
</template>
