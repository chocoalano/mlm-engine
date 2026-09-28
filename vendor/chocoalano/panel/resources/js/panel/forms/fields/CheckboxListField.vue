<script setup lang="ts">
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import FieldWrapper from '@/panel/forms/fields/FieldWrapper.vue';
import type { CheckboxListFieldDefinition } from '@/panel/types/form';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

const props = defineProps<{
    field: CheckboxListFieldDefinition;
    modelValue: unknown;
    error?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string[]] }>();

/**
 * Column counts map to literal classes. An interpolated `md:grid-cols-${n}`
 * would compile to nothing.
 */
const GRID_CLASSES: Record<number, string> = {
    1: 'grid-cols-1',
    2: 'grid-cols-1 sm:grid-cols-2',
    3: 'grid-cols-1 sm:grid-cols-3',
    4: 'grid-cols-2 sm:grid-cols-4',
};

const selected = computed<string[]>(() =>
    Array.isArray(props.modelValue) ? props.modelValue.map(String) : [],
);

const allSelected = computed(
    () =>
        props.field.options.length > 0 &&
        selected.value.length === props.field.options.length,
);

function toggle(value: string, checked: boolean): void {
    const next = selected.value.filter((entry) => entry !== value);

    if (checked) {
        next.push(value);
    }

    // Emitted in the schema's order rather than click order, so two users
    // picking the same set submit the same value.
    emit(
        'update:modelValue',
        props.field.options
            .map((option) => option.value)
            .filter((option) => next.includes(option)),
    );
}

function toggleAll(): void {
    emit(
        'update:modelValue',
        allSelected.value
            ? []
            : props.field.options.map((option) => option.value),
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
            role="group"
            :aria-labelledby="labelledBy"
            :aria-describedby="describedBy"
            :aria-invalid="invalid"
            class="flex flex-col gap-2"
        >
            <Button
                v-if="field.bulkToggleable && field.options.length > 1"
                type="button"
                variant="link"
                size="sm"
                class="h-auto w-fit p-0"
                :disabled="field.disabled"
                @click="toggleAll"
            >
                {{
                    allSelected
                        ? t('forms.checkbox_deselect_all')
                        : t('forms.checkbox_select_all')
                }}
            </Button>

            <div
                class="grid gap-3"
                :class="GRID_CLASSES[field.columns] ?? GRID_CLASSES[1]"
            >
                <div
                    v-for="option in field.options"
                    :key="option.value"
                    class="flex items-start gap-2"
                >
                    <Checkbox
                        :id="`${controlId}-${option.value}`"
                        :model-value="selected.includes(option.value)"
                        :disabled="field.disabled"
                        class="mt-0.5"
                        @update:model-value="
                            (checked) => toggle(option.value, checked === true)
                        "
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
            </div>
        </div>
    </FieldWrapper>
</template>
