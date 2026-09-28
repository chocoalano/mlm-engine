<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    focusAfterRemoval,
    survivorAfterRemoval,
} from '@/panel/forms/focusAfterRemoval';
import FieldWrapper from '@/panel/forms/fields/FieldWrapper.vue';
import RepeatedEntry from '@/panel/forms/fields/RepeatedEntry.vue';
import { resolveIcon } from '@/panel/icons/registry';
import type {
    BlockDefinition,
    BuilderFieldDefinition,
    FormValue,
    FormValues,
} from '@/panel/types/form';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

const props = defineProps<{
    field: BuilderFieldDefinition;
    modelValue: unknown;
    error?: string;
    errors: Record<string, string>;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: Array<{ type: string; data: FormValues }>];
}>();

/**
 * A list of blocks, each a different sub-schema chosen by its author.
 *
 * The difference from a repeater is that every entry names its own shape:
 * `{ type, data }` rather than a bare map. That name is what the server looks
 * the block up by, and a type it does not recognise is dropped there — so the
 * picker below can only ever offer what the schema declared.
 */
/**
 * Which blocks are folded up, by identity rather than by position — see the
 * same state in `RepeaterField` for why. A `Set` of indices leaves the collapse
 * behind on the slot when a block moves, so the block that slid into it is
 * drawn folded and the one the user folded springs open.
 */
const root = ref<HTMLElement | null>(null);

const collapsed = ref<Set<string>>(new Set());
const picking = ref(false);

const entries = computed<Array<{ type: string; data: FormValues }>>(() => {
    if (!Array.isArray(props.modelValue)) {
        return [];
    }

    const parsed: Array<{ type: string; data: FormValues }> = [];

    for (const entry of props.modelValue) {
        if (
            typeof entry !== 'object' ||
            entry === null ||
            Array.isArray(entry)
        ) {
            continue;
        }

        const type = (entry as { type?: unknown }).type;
        const data = (entry as { data?: unknown }).data;

        if (typeof type !== 'string') {
            continue;
        }

        parsed.push({
            type,
            data:
                typeof data === 'object' &&
                data !== null &&
                !Array.isArray(data)
                    ? (data as FormValues)
                    : {},
        });
    }

    return parsed;
});

const canAdd = computed(
    () =>
        !props.field.disabled &&
        (props.field.maxItems === null ||
            entries.value.length < props.field.maxItems),
);

const canDelete = computed(
    () =>
        !props.field.disabled &&
        (props.field.minItems === null ||
            entries.value.length > props.field.minItems),
);

/**
 * A DOM identity per block, stable across reordering — the same list, kept the
 * same way, as `RepeaterField`, and for the same two reasons. Every block of a
 * given type renders the same sub-schema, so without this two blocks of one
 * type produce two controls with one id; and it cannot be derived from the
 * block itself, because the form narrows every value it is given and that
 * rebuilds the objects on each edit.
 */
let counter = 0;

const blockIds = ref<string[]>([]);

watch(
    () => entries.value.length,
    (length) => {
        while (blockIds.value.length < length) {
            counter += 1;
            blockIds.value.push(String(counter));
        }

        if (blockIds.value.length > length) {
            // Blocks can disappear from underneath — a rebuilt schema, or a
            // shorter answer from the server. What was remembered about them
            // goes too, or the next block created would inherit it.
            for (const id of blockIds.value.splice(length)) {
                forget(id);
            }
        }
    },
    { immediate: true },
);

function identity(index: number): string {
    return blockIds.value[index] ?? String(index);
}

function domScope(index: number): string {
    return `${props.field.name}-${identity(index)}-`;
}

function isCollapsed(index: number): boolean {
    return collapsed.value.has(identity(index));
}

function forget(id: string): void {
    if (!collapsed.value.has(id)) {
        return;
    }

    const next = new Set(collapsed.value);

    next.delete(id);
    collapsed.value = next;
}

function block(type: string): BlockDefinition | null {
    return props.field.blocks.find((entry) => entry.name === type) ?? null;
}

function errorsFor(index: number): Record<string, string> {
    const prefix = `${props.field.name}.${index}.data.`;
    const scoped: Record<string, string> = {};

    for (const [key, message] of Object.entries(props.errors)) {
        if (key.startsWith(prefix)) {
            scoped[key.slice(prefix.length)] = message;
        }
    }

    return scoped;
}

function change(index: number, name: string, value: FormValue): void {
    emit(
        'update:modelValue',
        entries.value.map((entry, position) =>
            position === index
                ? { type: entry.type, data: { ...entry.data, [name]: value } }
                : entry,
        ),
    );
}

function add(definition: BlockDefinition): void {
    picking.value = false;

    emit('update:modelValue', [
        ...entries.value,
        {
            type: definition.name,
            data: { ...definition.emptyData } as FormValues,
        },
    ]);
}

function remove(index: number): void {
    // Worked out before the removal, while the identities still line up with
    // what is on screen.
    const target = survivorAfterRemoval(blockIds.value, index);

    forget(identity(index));

    blockIds.value.splice(index, 1);

    emit(
        'update:modelValue',
        entries.value.filter((_, position) => position !== index),
    );

    void focusAfterRemoval(root.value, target);
}

function move(index: number, offset: number): void {
    const target = index + offset;

    if (target < 0 || target >= entries.value.length) {
        return;
    }

    const next = [...entries.value];

    [next[index], next[target]] = [next[target], next[index]];

    // The identity follows the block, so the controls the user was looking at
    // keep their ids after the move.
    const ids = blockIds.value;

    [ids[index], ids[target]] = [ids[target], ids[index]];

    emit('update:modelValue', next);
}

function toggle(index: number): void {
    const id = identity(index);
    const next = new Set(collapsed.value);

    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }

    collapsed.value = next;
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
            ref="root"
            role="group"
            :aria-labelledby="labelledBy"
            :aria-describedby="describedBy"
            :aria-invalid="invalid"
            class="flex flex-col gap-3"
        >
            <div
                v-for="(entry, index) in entries"
                :key="index"
                :data-repeat-entry="identity(index)"
                class="rounded-md border border-input focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <div
                    class="flex items-center gap-2 border-b border-input bg-muted/40 px-3 py-2"
                >
                    <component
                        :is="resolveIcon(block(entry.type)?.icon)"
                        v-if="resolveIcon(block(entry.type)?.icon)"
                        class="size-4 text-muted-foreground"
                    />

                    <button
                        v-if="field.collapsible"
                        type="button"
                        data-repeat-focus
                        class="text-sm font-medium"
                        :aria-expanded="!isCollapsed(index)"
                        @click="toggle(index)"
                    >
                        {{ block(entry.type)?.label ?? entry.type }}
                    </button>
                    <span v-else class="text-sm font-medium">
                        {{ block(entry.type)?.label ?? entry.type }}
                    </span>

                    <div class="ml-auto flex items-center gap-1">
                        <Button
                            v-if="field.reorderable"
                            type="button"
                            variant="ghost"
                            size="sm"
                            :disabled="field.disabled || index === 0"
                            :aria-label="t('forms.move_block_up')"
                            @click="move(index, -1)"
                        >
                            ↑
                        </Button>
                        <Button
                            v-if="field.reorderable"
                            type="button"
                            variant="ghost"
                            size="sm"
                            :disabled="
                                field.disabled || index === entries.length - 1
                            "
                            :aria-label="t('forms.move_block_down')"
                            @click="move(index, 1)"
                        >
                            ↓
                        </Button>
                        <Button
                            v-if="canDelete"
                            type="button"
                            variant="ghost"
                            size="sm"
                            :aria-label="t('forms.remove_block')"
                            @click="remove(index)"
                        >
                            {{ t('forms.remove') }}
                        </Button>
                    </div>
                </div>

                <!--
                    A block whose type the schema no longer declares is shown
                    as itself rather than dropped: the value is still on the
                    record, and silently discarding it on the next save would
                    lose content nobody asked to remove.
                -->
                <div
                    v-show="!isCollapsed(index)"
                    class="flex flex-col gap-4 p-3"
                >
                    <RepeatedEntry
                        v-if="block(entry.type)"
                        :schema="block(entry.type)?.schema ?? []"
                        :values="entry.data"
                        :errors="errorsFor(index)"
                        :dom-scope="domScope(index)"
                        :path-scope="`${field.name}.${index}.data.`"
                        @change="(name, value) => change(index, name, value)"
                    />
                    <p v-else class="text-sm text-muted-foreground">
                        {{ t('forms.block_unavailable') }}
                    </p>
                </div>
            </div>

            <p
                v-if="entries.length === 0"
                class="text-sm text-muted-foreground"
            >
                {{ t('forms.no_blocks') }}
            </p>

            <div v-if="canAdd" class="flex flex-col gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    data-repeat-add
                    class="w-fit"
                    :aria-expanded="picking"
                    @click="picking = !picking"
                >
                    {{ field.addLabel }}
                </Button>

                <div v-if="picking" class="flex flex-wrap gap-2">
                    <Button
                        v-for="definition in field.blocks"
                        :key="definition.name"
                        type="button"
                        variant="secondary"
                        size="sm"
                        @click="add(definition)"
                    >
                        <component
                            :is="resolveIcon(definition.icon)"
                            v-if="resolveIcon(definition.icon)"
                            class="size-4"
                        />
                        {{ definition.label }}
                    </Button>
                </div>
            </div>
        </div>
    </FieldWrapper>
</template>
