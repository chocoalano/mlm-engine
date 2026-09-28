<script setup lang="ts">
import BuilderField from '@/panel/forms/fields/BuilderField.vue';
import CheckboxField from '@/panel/forms/fields/CheckboxField.vue';
import CheckboxListField from '@/panel/forms/fields/CheckboxListField.vue';
import CodeEditorField from '@/panel/forms/fields/CodeEditorField.vue';
import ColorPickerField from '@/panel/forms/fields/ColorPickerField.vue';
import CustomFieldRenderer from '@/panel/forms/fields/CustomFieldRenderer.vue';
import DateField from '@/panel/forms/fields/DateField.vue';
import DateTimeField from '@/panel/forms/fields/DateTimeField.vue';
import FileUploadField from '@/panel/forms/fields/FileUploadField.vue';
import KeyValueField from '@/panel/forms/fields/KeyValueField.vue';
import MarkdownEditorField from '@/panel/forms/fields/MarkdownEditorField.vue';
import MoneyField from '@/panel/forms/fields/MoneyField.vue';
import NumberField from '@/panel/forms/fields/NumberField.vue';
import PasswordField from '@/panel/forms/fields/PasswordField.vue';
import RadioField from '@/panel/forms/fields/RadioField.vue';
import RepeaterField from '@/panel/forms/fields/RepeaterField.vue';
import RichEditorField from '@/panel/forms/fields/RichEditorField.vue';
import SelectField from '@/panel/forms/fields/SelectField.vue';
import SliderField from '@/panel/forms/fields/SliderField.vue';
import TagsInputField from '@/panel/forms/fields/TagsInputField.vue';
import TextareaField from '@/panel/forms/fields/TextareaField.vue';
import TextInputField from '@/panel/forms/fields/TextInputField.vue';
import TimeField from '@/panel/forms/fields/TimeField.vue';
import ToggleButtonsField from '@/panel/forms/fields/ToggleButtonsField.vue';
import ToggleField from '@/panel/forms/fields/ToggleField.vue';
import type {
    FieldDefinition,
    FormValue,
    FormValues,
} from '@/panel/types/form';

/**
 * Dispatches a field definition to its control.
 *
 * The switch below is exhaustive: `assertNever` makes adding a PHP field
 * type without a renderer a compile error rather than a silently missing
 * input.
 */
const props = defineProps<{
    field: FieldDefinition;
    values: FormValues;
    errors: Record<string, string>;
}>();

const emit = defineEmits<{ change: [name: string, value: FormValue] }>();

function assertNever(value: never): never {
    throw new Error(`Unhandled field type: ${JSON.stringify(value)}`);
}

function ensureHandled(field: FieldDefinition): void {
    switch (field.type) {
        case 'text':
        case 'textarea':
        case 'password':
        case 'number':
        case 'money':
        case 'hidden':
        case 'checkbox':
        case 'toggle':
        case 'select':
        case 'date':
        case 'datetime':
        case 'time':
        case 'radio':
        case 'checkbox_list':
        case 'toggle_buttons':
        case 'color_picker':
        case 'slider':
        case 'tags_input':
        case 'key_value':
        case 'rich_editor':
        case 'markdown_editor':
        case 'code_editor':
        case 'file_upload':
        case 'repeater':
        case 'builder':
        case 'custom':
            return;
        default:
            assertNever(field);
    }
}

ensureHandled(props.field);
</script>

<template>
    <!-- A hidden field carries its value without occupying layout space. -->
    <template v-if="field.type === 'hidden'" />

    <TextInputField
        v-else-if="field.type === 'text'"
        :field="field"
        :model-value="values[field.name]"
        :error="errors[field.name]"
        @update:model-value="(value) => emit('change', field.name, value)"
    />

    <TextareaField
        v-else-if="field.type === 'textarea'"
        :field="field"
        :model-value="values[field.name]"
        :error="errors[field.name]"
        @update:model-value="(value) => emit('change', field.name, value)"
    />

    <PasswordField
        v-else-if="field.type === 'password'"
        :field="field"
        :model-value="values[field.name]"
        :confirmation-value="values[`${field.name}_confirmation`]"
        :error="errors[field.name]"
        :confirmation-error="errors[`${field.name}_confirmation`]"
        @update:model-value="(value) => emit('change', field.name, value)"
        @update:confirmation-value="
            (value) => emit('change', `${field.name}_confirmation`, value)
        "
    />

    <NumberField
        v-else-if="field.type === 'number'"
        :field="field"
        :model-value="values[field.name]"
        :error="errors[field.name]"
        @update:model-value="(value) => emit('change', field.name, value)"
    />

    <MoneyField
        v-else-if="field.type === 'money'"
        :field="field"
        :model-value="values[field.name]"
        :error="errors[field.name]"
        @update:model-value="(value) => emit('change', field.name, value)"
    />

    <CheckboxField
        v-else-if="field.type === 'checkbox'"
        :field="field"
        :model-value="values[field.name]"
        :error="errors[field.name]"
        @update:model-value="(value) => emit('change', field.name, value)"
    />

    <ToggleField
        v-else-if="field.type === 'toggle'"
        :field="field"
        :model-value="values[field.name]"
        :error="errors[field.name]"
        @update:model-value="(value) => emit('change', field.name, value)"
    />

    <SelectField
        v-else-if="field.type === 'select'"
        :field="field"
        :model-value="values[field.name]"
        :error="errors[field.name]"
        @update:model-value="(value) => emit('change', field.name, value)"
    />

    <DateField
        v-else-if="field.type === 'date'"
        :field="field"
        :model-value="values[field.name]"
        :error="errors[field.name]"
        @update:model-value="(value) => emit('change', field.name, value)"
    />

    <DateTimeField
        v-else-if="field.type === 'datetime'"
        :field="field"
        :model-value="values[field.name]"
        :error="errors[field.name]"
        @update:model-value="(value) => emit('change', field.name, value)"
    />

    <TimeField
        v-else-if="field.type === 'time'"
        :field="field"
        :model-value="values[field.name]"
        :error="errors[field.name]"
        @update:model-value="(value) => emit('change', field.name, value)"
    />

    <RadioField
        v-else-if="field.type === 'radio'"
        :field="field"
        :model-value="values[field.name]"
        :error="errors[field.name]"
        @update:model-value="(value) => emit('change', field.name, value)"
    />

    <CheckboxListField
        v-else-if="field.type === 'checkbox_list'"
        :field="field"
        :model-value="values[field.name]"
        :error="errors[field.name]"
        @update:model-value="(value) => emit('change', field.name, value)"
    />

    <ToggleButtonsField
        v-else-if="field.type === 'toggle_buttons'"
        :field="field"
        :model-value="values[field.name]"
        :error="errors[field.name]"
        @update:model-value="(value) => emit('change', field.name, value)"
    />

    <ColorPickerField
        v-else-if="field.type === 'color_picker'"
        :field="field"
        :model-value="values[field.name]"
        :error="errors[field.name]"
        @update:model-value="(value) => emit('change', field.name, value)"
    />

    <SliderField
        v-else-if="field.type === 'slider'"
        :field="field"
        :model-value="values[field.name]"
        :error="errors[field.name]"
        @update:model-value="(value) => emit('change', field.name, value)"
    />

    <TagsInputField
        v-else-if="field.type === 'tags_input'"
        :field="field"
        :model-value="values[field.name]"
        :error="errors[field.name]"
        @update:model-value="(value) => emit('change', field.name, value)"
    />

    <KeyValueField
        v-else-if="field.type === 'key_value'"
        :field="field"
        :model-value="values[field.name]"
        :error="errors[field.name]"
        @update:model-value="(value) => emit('change', field.name, value)"
    />

    <RichEditorField
        v-else-if="field.type === 'rich_editor'"
        :field="field"
        :model-value="values[field.name]"
        :error="errors[field.name]"
        @update:model-value="(value) => emit('change', field.name, value)"
    />

    <MarkdownEditorField
        v-else-if="field.type === 'markdown_editor'"
        :field="field"
        :model-value="values[field.name]"
        :error="errors[field.name]"
        @update:model-value="(value) => emit('change', field.name, value)"
    />

    <CodeEditorField
        v-else-if="field.type === 'code_editor'"
        :field="field"
        :model-value="values[field.name]"
        :error="errors[field.name]"
        @update:model-value="(value) => emit('change', field.name, value)"
    />

    <FileUploadField
        v-else-if="field.type === 'file_upload'"
        :field="field"
        :model-value="values[field.name]"
        :error="errors[field.name]"
        @update:model-value="(value) => emit('change', field.name, value)"
    />

    <!--
        A repeater and a builder hold whole sub-forms, so they are given the
        form's errors rather than their own: the server keys a message inside
        one as `items.0.title`, and only the field itself knows how to find
        the entry that produced it.
    -->
    <RepeaterField
        v-else-if="field.type === 'repeater'"
        :field="field"
        :model-value="values[field.name]"
        :error="errors[field.name]"
        :errors="errors"
        @update:model-value="(value) => emit('change', field.name, value)"
    />

    <BuilderField
        v-else-if="field.type === 'builder'"
        :field="field"
        :model-value="values[field.name]"
        :error="errors[field.name]"
        :errors="errors"
        @update:model-value="(value) => emit('change', field.name, value)"
    />

    <CustomFieldRenderer
        v-else
        :field="field"
        :model-value="values[field.name]"
        :error="errors[field.name]"
        @update:model-value="(value) => emit('change', field.name, value)"
    />
</template>
