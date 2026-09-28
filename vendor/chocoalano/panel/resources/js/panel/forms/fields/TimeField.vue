<script setup lang="ts">
import FieldWrapper from '@/panel/forms/fields/FieldWrapper.vue';
import PanelTimePicker from '@/panel/components/PanelTimePicker.vue';
import type { TimeFieldDefinition } from '@/panel/types/form';

defineProps<{
    field: TimeFieldDefinition;
    modelValue: unknown;
    error?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>();
</script>

<template>
    <FieldWrapper
        v-slot="{ controlId, describedBy, invalid, labelledBy }"
        group
        :name="field.name"
        :inline-label="field.inlineLabel"
        :label="field.label"
        :required="field.required"
        :helper-text="field.helperText"
        :error="error"
    >
        <!--
            `seconds` used to reach the browser as `step="1"`, which is what
            made a native control show a seconds box at all — without it some
            browsers round to the minute and a value the server sent with
            seconds was truncated on the first edit. The panel's own control
            has no such default to work around: it renders a seconds select
            when the field asks for one and does not when it does not, so the
            flag is passed through as itself.
        -->
        <PanelTimePicker
            :id="controlId"
            :model-value="typeof modelValue === 'string' ? modelValue : null"
            :seconds="field.seconds"
            :disabled="field.disabled"
            :invalid="invalid"
            :described-by="describedBy"
            :labelled-by="labelledBy"
            :clearable="!field.required"
            @update:model-value="(value) => emit('update:modelValue', value)"
        />
    </FieldWrapper>
</template>
