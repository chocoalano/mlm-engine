<script setup lang="ts">
import { computed } from 'vue';
import FieldWrapper from '@/panel/forms/fields/FieldWrapper.vue';
import type { SliderFieldDefinition } from '@/panel/types/form';

const props = defineProps<{
    field: SliderFieldDefinition;
    modelValue: unknown;
    error?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: number] }>();

/**
 * A range input has no empty state: it always points somewhere. An unset
 * value therefore reads as the minimum, which is what the control would show
 * anyway — the alternative is a thumb that lies about where it is.
 */
const value = computed(() => {
    const candidate = Number(props.modelValue);

    return Number.isFinite(candidate) ? candidate : props.field.min;
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
        <div class="flex items-center gap-3">
            <input
                :id="controlId"
                :aria-describedby="describedBy"
                type="range"
                class="h-2 w-full cursor-pointer appearance-none rounded-full bg-muted accent-primary disabled:cursor-not-allowed disabled:opacity-50"
                :min="field.min"
                :max="field.max"
                :step="field.step"
                :value="value"
                :disabled="field.disabled"
                :aria-invalid="invalid"
                @input="
                    (event) =>
                        emit(
                            'update:modelValue',
                            Number((event.target as HTMLInputElement).value),
                        )
                "
            />
            <span
                v-if="field.showValue"
                class="w-12 shrink-0 text-right text-sm text-muted-foreground tabular-nums"
            >
                {{ value }}
            </span>
        </div>
    </FieldWrapper>
</template>
