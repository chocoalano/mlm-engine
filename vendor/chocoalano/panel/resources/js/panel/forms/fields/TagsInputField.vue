<script setup lang="ts">
import { computed, ref } from 'vue';
import { Input } from '@/components/ui/input';
import FieldWrapper from '@/panel/forms/fields/FieldWrapper.vue';
import type { TagsInputFieldDefinition } from '@/panel/types/form';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

const props = defineProps<{
    field: TagsInputFieldDefinition;
    modelValue: unknown;
    error?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string[]] }>();

const draft = ref('');

const tags = computed<string[]>(() =>
    Array.isArray(props.modelValue) ? props.modelValue.map(String) : [],
);

const atLimit = computed(
    () =>
        props.field.maxTags !== null &&
        tags.value.length >= props.field.maxTags,
);

/** Suggestions already taken are not offered again. */
const available = computed(() =>
    props.field.suggestions.filter(
        (suggestion) => !tags.value.includes(suggestion),
    ),
);

function add(raw: string): void {
    const value = raw.trim();

    // A tag that is blank, already there, or over the length the server will
    // reject is refused here rather than submitted to be told about.
    if (
        value === '' ||
        tags.value.includes(value) ||
        atLimit.value ||
        (props.field.maxLength !== null && value.length > props.field.maxLength)
    ) {
        return;
    }

    emit('update:modelValue', [...tags.value, value]);
}

function commitDraft(): void {
    const separator = props.field.separator;

    // A separator makes pasting a list work: "red, green" becomes two tags
    // rather than one with a comma in it.
    if (separator !== null && draft.value.includes(separator)) {
        for (const part of draft.value.split(separator)) {
            add(part);
        }
    } else {
        add(draft.value);
    }

    draft.value = '';
}

function remove(tag: string): void {
    emit(
        'update:modelValue',
        tags.value.filter((entry) => entry !== tag),
    );
}

/**
 * Backspace on an empty box removes the last tag, which is what every other
 * control of this shape does.
 */
function onBackspace(): void {
    if (draft.value === '' && tags.value.length > 0) {
        remove(tags.value[tags.value.length - 1]);
    }
}
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
            <div v-if="tags.length > 0" class="flex flex-wrap gap-1.5">
                <span
                    v-for="tag in tags"
                    :key="tag"
                    class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                >
                    {{ tag }}
                    <button
                        type="button"
                        class="text-muted-foreground hover:text-foreground disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="field.disabled"
                        :aria-label="t('forms.remove_item', { item: tag })"
                        @click="remove(tag)"
                    >
                        &times;
                    </button>
                </span>
            </div>

            <Input
                :id="controlId"
                :aria-describedby="describedBy"
                :model-value="draft"
                :placeholder="field.placeholder ?? undefined"
                :disabled="field.disabled || atLimit"
                :maxlength="field.maxLength ?? undefined"
                :aria-invalid="invalid"
                :list="
                    available.length > 0
                        ? `${controlId}-suggestions`
                        : undefined
                "
                @update:model-value="(next) => (draft = String(next))"
                @keydown.enter.prevent="commitDraft"
                @keydown.backspace="onBackspace"
                @blur="commitDraft"
            />

            <datalist
                v-if="available.length > 0"
                :id="`${controlId}-suggestions`"
            >
                <option
                    v-for="suggestion in available"
                    :key="suggestion"
                    :value="suggestion"
                />
            </datalist>
        </div>
    </FieldWrapper>
</template>
