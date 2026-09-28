<script setup lang="ts">
import { computed } from 'vue';
import FieldWrapper from '@/panel/forms/fields/FieldWrapper.vue';
import { resolveIcon } from '@/panel/icons/registry';
import { SELECTED_CLASSES } from '@/panel/palette';
import type { ToggleButtonsFieldDefinition } from '@/panel/types/form';

const props = defineProps<{
    field: ToggleButtonsFieldDefinition;
    modelValue: unknown;
    error?: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string | string[] | null];
}>();

/**
 * One control for two shapes: a single choice holds a key, a multiple one
 * holds a set. Both are compared as a set here so the template has one
 * answer to "is this pressed".
 */
const selected = computed<string[]>(() => {
    if (Array.isArray(props.modelValue)) {
        return props.modelValue.map(String);
    }

    return props.modelValue === null ||
        props.modelValue === undefined ||
        props.modelValue === ''
        ? []
        : [String(props.modelValue)];
});

function press(value: string): void {
    if (props.field.disabled) {
        return;
    }

    if (!props.field.multiple) {
        // Pressing the pressed one clears it, which is how a single choice
        // becomes unset again without a separate control saying "none".
        emit(
            'update:modelValue',
            selected.value.includes(value) ? null : value,
        );

        return;
    }

    const next = selected.value.includes(value)
        ? selected.value.filter((entry) => entry !== value)
        : [...selected.value, value];

    emit(
        'update:modelValue',
        props.field.options
            .map((option) => option.value)
            .filter((option) => next.includes(option)),
    );
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
        <div
            :id="controlId"
            class="flex gap-2"
            :class="field.inline ? 'flex-wrap' : 'flex-col items-start'"
            role="group"
            :aria-labelledby="labelledBy"
            :aria-describedby="describedBy"
            :aria-invalid="invalid"
        >
            <button
                v-for="option in field.options"
                :key="option.value"
                type="button"
                :disabled="field.disabled"
                :aria-pressed="selected.includes(option.value)"
                class="inline-flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                :class="
                    selected.includes(option.value)
                        ? SELECTED_CLASSES[option.color]
                        : 'border-input bg-background text-foreground hover:bg-accent'
                "
                @click="press(option.value)"
            >
                <component
                    :is="resolveIcon(option.icon)"
                    v-if="resolveIcon(option.icon)"
                    class="size-4"
                />
                {{ option.label }}
            </button>
        </div>
    </FieldWrapper>
</template>
