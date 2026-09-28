<script setup lang="ts">
import { useTranslator } from '@/composables/useTranslator';
import { computed } from 'vue';
import { Input } from '@/components/ui/input';
import FieldWrapper from '@/panel/forms/fields/FieldWrapper.vue';
import type { ColorPickerFieldDefinition } from '@/panel/types/form';

const { t } = useTranslator();

const props = defineProps<{
    field: ColorPickerFieldDefinition;
    modelValue: unknown;
    error?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const value = computed(() =>
    typeof props.modelValue === 'string' ? props.modelValue : '',
);

/**
 * A native colour input has to hold a valid hex; the text box beside it does
 * not. Anything unparseable shows as black in the swatch while the text
 * stays exactly as typed, so a half-finished `#ab` is not silently rewritten
 * under the cursor.
 */
const swatch = computed(() =>
    /^#[0-9a-f]{6}$/i.test(value.value) ? value.value : '#000000',
);
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
        <div class="flex flex-col gap-2">
            <div class="flex items-center gap-2">
                <input
                    :id="`${controlId}-swatch`"
                    type="color"
                    class="size-9 shrink-0 cursor-pointer rounded-md border border-input bg-background p-1 disabled:cursor-not-allowed disabled:opacity-50"
                    :value="swatch"
                    :disabled="field.disabled"
                    :aria-label="
                        t('forms.color_for_field', { field: field.label })
                    "
                    @input="
                        (event) =>
                            emit(
                                'update:modelValue',
                                (event.target as HTMLInputElement).value,
                            )
                    "
                />
                <Input
                    :id="controlId"
                    :aria-describedby="describedBy"
                    :model-value="value"
                    :placeholder="field.placeholder ?? '#000000'"
                    :disabled="field.disabled"
                    :aria-invalid="invalid"
                    @update:model-value="
                        (next) => emit('update:modelValue', String(next))
                    "
                />
            </div>

            <div
                v-if="field.swatches.length > 0"
                class="flex flex-wrap gap-1.5"
            >
                <button
                    v-for="preset in field.swatches"
                    :key="preset"
                    type="button"
                    class="size-6 rounded-full border border-input disabled:cursor-not-allowed disabled:opacity-50"
                    :class="
                        value === preset ? 'ring-2 ring-ring ring-offset-2' : ''
                    "
                    :style="{ backgroundColor: preset }"
                    :disabled="field.disabled"
                    :aria-label="preset"
                    @click="emit('update:modelValue', preset)"
                />
            </div>
        </div>
    </FieldWrapper>
</template>
