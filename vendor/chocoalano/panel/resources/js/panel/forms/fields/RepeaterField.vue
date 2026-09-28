<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    focusAfterRemoval,
    survivorAfterRemoval,
} from '@/panel/forms/focusAfterRemoval';
import FieldWrapper from '@/panel/forms/fields/FieldWrapper.vue';
import RepeatedEntry from '@/panel/forms/fields/RepeatedEntry.vue';
import type {
    FormValue,
    FormValues,
    RepeaterFieldDefinition,
} from '@/panel/types/form';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

const props = defineProps<{
    field: RepeaterFieldDefinition;
    modelValue: unknown;
    error?: string;
    /**
     * The whole form's errors. A repeater needs them keyed as the server
     * sends them — `items.0.title` — so a message lands on the entry that
     * produced it rather than on the repeater as a whole.
     */
    errors: Record<string, string>;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: FormValues[]] }>();

/**
 * A list of entries, each edited by the same sub-schema.
 *
 * The entries are rendered by the ordinary component renderer against their
 * own values, so a field inside a repeater behaves exactly as it does
 * outside one — including conditions, which read the entry rather than the
 * form. That last part is why the sub-schema's field names are plain: inside
 * an entry, `type` means this entry's type.
 */
/**
 * Which entries are folded up, by identity rather than by position.
 *
 * A `Set` of array indices was the obvious shape and the wrong one. Collapse
 * an entry, move it down, and the index stays behind: the entry that slid up
 * into the vacated slot is drawn folded, and the one the user actually folded
 * springs open. Nothing about either entry changed except where it sits.
 *
 * It is the same mistake as the duplicate DOM ids this file already carries a
 * fix for, one level up — a position is not an identity — so it takes the same
 * answer, and deliberately the *same* identity rather than a second one.
 */
const root = ref<HTMLElement | null>(null);

const collapsed = ref<Set<string>>(new Set());

/**
 * A stable identity per entry.
 *
 * Two things hang off it: the DOM ids of the controls inside an entry, and
 * which entries are collapsed. One identity for both, because they are the
 * same question — which entry is this? — and two identities would be two
 * things to keep in step through every reorder.
 *
 * Every entry renders the same sub-schema, so without this every entry's
 * `name` field is a control called `name` — duplicate ids, and a label that
 * focuses the first entry's input whichever entry it belongs to.
 *
 * Held as a parallel list rather than derived from the entry object, because
 * entry objects do not survive an edit: the form narrows every value it is
 * given (`toFormValue`), which rebuilds each entry, so nothing about the value
 * is stable enough to key an identity on. And it is not the index either — a
 * position is not an identity, and renumbering on every move would change the
 * ids of controls nobody touched.
 *
 * So the list is maintained alongside the entries: reordering swaps two of
 * these, removing drops one, adding appends a new one. It is local view state
 * and none of it reaches the payload.
 */
let counter = 0;

const itemIds = ref<string[]>([]);

function nextId(): string {
    counter += 1;

    return String(counter);
}

const items = computed<FormValues[]>(() => {
    if (!Array.isArray(props.modelValue)) {
        return [];
    }

    return props.modelValue.map((entry) =>
        typeof entry === 'object' && entry !== null && !Array.isArray(entry)
            ? (entry as FormValues)
            : {},
    );
});

watch(
    () => items.value.length,
    (length) => {
        while (itemIds.value.length < length) {
            itemIds.value.push(nextId());
        }

        if (itemIds.value.length > length) {
            // Entries can also disappear from underneath — a `live()` rebuild,
            // or the server answering with a shorter list. Their identities go
            // with them, and so does anything remembered about them; a key
            // left behind would be inherited by whatever is created next.
            for (const id of itemIds.value.splice(length)) {
                forget(id);
            }
        }
    },
    { immediate: true },
);

function identity(index: number): string {
    return itemIds.value[index] ?? String(index);
}

function domScope(index: number): string {
    return `${props.field.name}-${identity(index)}-`;
}

function isCollapsed(index: number): boolean {
    return collapsed.value.has(identity(index));
}

function forget(id: string): void {
    labels.value.delete(id);

    if (!collapsed.value.has(id)) {
        return;
    }

    const next = new Set(collapsed.value);

    next.delete(id);
    collapsed.value = next;
}

/**
 * What each entry is called, by identity.
 *
 * `itemLabels` arrives as a list indexed by position, because that is how the
 * server has it: it walks the value and calls the schema's `itemLabel()`
 * closure once per entry. But the label describes the *entry*, not the slot —
 * and reading it back by position is the same mistake as the collapse state,
 * with a worse symptom. Move an entry and its header names its neighbour;
 * remove one and the button that deletes what is now the second entry still
 * says "Remove B".
 *
 * So the list is paired with identities when it arrives, and read by identity
 * afterwards. A client-side reorder needs no maintenance at all: the label is
 * already attached to the thing that moved.
 */
const labels = ref(new Map<string, string>());

watch(
    () => props.field.itemLabels,
    (incoming) => {
        // Runs after the identity watcher above — declaration order is flush
        // order — so the ids are already sized for the incoming list. Pairing
        // by position is right at exactly this moment and at no other: this is
        // a fresh payload, so the server's order is the order on screen.
        const paired = new Map<string, string>();

        incoming.forEach((label, index) => {
            if (label !== null && label !== undefined) {
                paired.set(identity(index), label);
            }
        });

        labels.value = paired;
    },
    { immediate: true },
);

const canAdd = computed(
    () =>
        props.field.addable &&
        !props.field.disabled &&
        (props.field.maxItems === null ||
            items.value.length < props.field.maxItems),
);

const canDelete = computed(
    () =>
        props.field.deletable &&
        !props.field.disabled &&
        (props.field.minItems === null ||
            items.value.length > props.field.minItems),
);

/**
 * The label an entry wears, from the server when it declared one and a
 * position otherwise.
 */
function itemLabel(index: number): string {
    return labels.value.get(identity(index)) ?? `Item ${index + 1}`;
}

function errorsFor(index: number): Record<string, string> {
    const prefix = `${props.field.name}.${index}.`;
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
        items.value.map((entry, position) =>
            position === index ? { ...entry, [name]: value } : entry,
        ),
    );
}

function add(): void {
    emit('update:modelValue', [
        ...items.value,
        { ...props.field.emptyItem } as FormValues,
    ]);
}

function remove(index: number): void {
    // Worked out before the removal, while the identities still line up with
    // what is on screen.
    const target = survivorAfterRemoval(itemIds.value, index);

    // Dropped before the emit, so nothing that is remembered about this entry
    // can be picked up by a later one. Identities are never reused, but a
    // stale key is a leak either way.
    forget(identity(index));

    itemIds.value.splice(index, 1);

    emit(
        'update:modelValue',
        items.value.filter((_, position) => position !== index),
    );

    // The button that was just activated is gone, and the DOM node at that
    // position now belongs to whichever entry moved up into it — so without
    // this, focus is left on a different entry's Remove.
    void focusAfterRemoval(root.value, target);
}

function move(index: number, offset: number): void {
    const target = index + offset;

    if (target < 0 || target >= items.value.length) {
        return;
    }

    const next = [...items.value];

    [next[index], next[target]] = [next[target], next[index]];

    // The identity follows the entry, so the control the user was looking at
    // keeps its id after the move.
    const ids = itemIds.value;

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
        <!--
            The list is what the label names: there is no single control here,
            and each entry's fields carry their own identity — see
            `RepeatedEntry`.
        -->
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
                v-for="(item, index) in items"
                :key="index"
                :data-repeat-entry="identity(index)"
                class="rounded-md border border-input focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <div
                    class="flex items-center gap-2 border-b border-input bg-muted/40 px-3 py-2"
                >
                    <button
                        v-if="field.collapsible"
                        type="button"
                        data-repeat-focus
                        class="text-sm font-medium"
                        :aria-expanded="!isCollapsed(index)"
                        @click="toggle(index)"
                    >
                        {{ itemLabel(index) }}
                    </button>
                    <span v-else class="text-sm font-medium">
                        {{ itemLabel(index) }}
                    </span>

                    <div class="ml-auto flex items-center gap-1">
                        <Button
                            v-if="field.reorderable"
                            type="button"
                            variant="ghost"
                            size="sm"
                            :disabled="field.disabled || index === 0"
                            :aria-label="
                                t('forms.move_item_up', {
                                    item: itemLabel(index),
                                })
                            "
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
                                field.disabled || index === items.length - 1
                            "
                            :aria-label="
                                t('forms.move_item_down', {
                                    item: itemLabel(index),
                                })
                            "
                            @click="move(index, 1)"
                        >
                            ↓
                        </Button>
                        <Button
                            v-if="canDelete"
                            type="button"
                            variant="ghost"
                            size="sm"
                            :aria-label="
                                t('forms.remove_item', {
                                    item: itemLabel(index),
                                })
                            "
                            @click="remove(index)"
                        >
                            {{ t('forms.remove') }}
                        </Button>
                    </div>
                </div>

                <div
                    v-show="!isCollapsed(index)"
                    class="flex flex-col gap-4 p-3"
                >
                    <RepeatedEntry
                        :schema="field.schema"
                        :values="item"
                        :errors="errorsFor(index)"
                        :dom-scope="domScope(index)"
                        :path-scope="`${field.name}.${index}.`"
                        @change="(name, value) => change(index, name, value)"
                    />
                </div>
            </div>

            <p v-if="items.length === 0" class="text-sm text-muted-foreground">
                {{ t('forms.no_entries') }}
            </p>

            <Button
                v-if="canAdd"
                type="button"
                variant="outline"
                size="sm"
                data-repeat-add
                class="w-fit"
                @click="add"
            >
                {{ field.addLabel }}
            </Button>
        </div>
    </FieldWrapper>
</template>
