<script setup lang="ts">
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import FieldWrapper from '@/panel/forms/fields/FieldWrapper.vue';
import type { KeyValueFieldDefinition } from '@/panel/types/form';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

const props = defineProps<{
    field: KeyValueFieldDefinition;
    modelValue: unknown;
    error?: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: Record<string, string>];
}>();

/**
 * Edited as an ordered list of pairs and submitted as a map.
 *
 * The list is what makes editing possible at all: a map cannot hold two
 * entries with the same key, and renaming one by typing goes through states
 * where it briefly collides with another or is blank. Rebuilding the map on
 * every keystroke would drop rows out from under the cursor.
 */
const pairs = computed<Array<[string, string]>>(() => {
    const value = props.modelValue;

    if (typeof value !== 'object' || value === null || Array.isArray(value)) {
        return [];
    }

    return Object.entries(value).map(([key, entry]) => [
        key,
        entry === null || entry === undefined ? '' : String(entry),
    ]);
});

const atLimit = computed(
    () =>
        props.field.maxPairs !== null &&
        pairs.value.length >= props.field.maxPairs,
);

function commit(next: Array<[string, string]>): void {
    const map: Record<string, string> = {};

    for (const [key, value] of next) {
        // A blank key has nowhere to go in a map. It stays in the list until
        // it is typed into, and simply is not submitted before then.
        if (key !== '') {
            map[key] = value;
        }
    }

    emit('update:modelValue', map);
}

function setKey(index: number, key: string): void {
    const next = pairs.value.map<[string, string]>((pair) => [...pair]);

    next[index] = [key, next[index][1]];

    commit(next);
}

function setValue(index: number, value: string): void {
    const next = pairs.value.map<[string, string]>((pair) => [...pair]);

    next[index] = [next[index][0], value];

    commit(next);
}

function add(): void {
    commit([...pairs.value, ['', '']]);
}

function remove(index: number): void {
    commit(pairs.value.filter((_, position) => position !== index));
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
            <div
                v-if="pairs.length > 0"
                class="grid grid-cols-[1fr_1fr_auto] gap-2 text-xs text-muted-foreground"
            >
                <span>{{ field.keyLabel }}</span>
                <span>{{ field.valueLabel }}</span>
                <span class="w-8" />
            </div>

            <div
                v-for="(pair, index) in pairs"
                :key="index"
                class="grid grid-cols-[1fr_1fr_auto] items-center gap-2"
            >
                <Input
                    :model-value="pair[0]"
                    :disabled="field.disabled || !field.editableKeys"
                    :aria-label="field.keyLabel"
                    @update:model-value="(next) => setKey(index, String(next))"
                />
                <Input
                    :model-value="pair[1]"
                    :disabled="field.disabled"
                    :aria-label="field.valueLabel"
                    @update:model-value="
                        (next) => setValue(index, String(next))
                    "
                />
                <Button
                    v-if="field.deletable"
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="w-8"
                    :disabled="field.disabled"
                    :aria-label="
                        t('forms.remove_item', {
                            item: pair[0] || field.keyLabel,
                        })
                    "
                    @click="remove(index)"
                >
                    &times;
                </Button>
                <span v-else class="w-8" />
            </div>

            <p v-if="pairs.length === 0" class="text-sm text-muted-foreground">
                {{ t('forms.no_entries') }}
            </p>

            <Button
                v-if="field.addable"
                type="button"
                variant="outline"
                size="sm"
                class="w-fit"
                :disabled="field.disabled || atLimit"
                @click="add"
            >
                {{ t('forms.add_row') }}
            </Button>
        </div>
    </FieldWrapper>
</template>
