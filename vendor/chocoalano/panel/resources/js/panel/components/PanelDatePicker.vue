<script setup lang="ts">
import { Calendar as CalendarIcon, X } from '@lucide/vue';
import { CalendarDate, parseDate } from '@internationalized/date';
import { computed, ref, watch } from 'vue';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { useTranslator } from '@/composables/useTranslator';

const { t, locale } = useTranslator();

/** Editable ISO date with a calendar popover. */
const props = withDefaults(
    defineProps<{
        /** ISO `YYYY-MM-DD`, or null for no date. */
        modelValue: string | null;
        id?: string;
        disabled?: boolean;
        /** ISO bounds. A day outside them is rendered unselectable. */
        min?: string | null;
        max?: string | null;
        /** Shown on the trigger while no date is set. */
        placeholder?: string;
        invalid?: boolean;
        ariaLabel?: string;
        /**
         * Helper and error ids, placed on the trigger.
         *
         * Declared as a prop rather than left to fall through: this component
         * has a wrapping `<div>` for the clear button to sit against, and an
         * undeclared attribute lands on that wrapper. `aria-describedby` on a
         * `<div>` describes nothing anybody focuses — the field's helper text
         * and its error message were both being announced to no one.
         */
        describedBy?: string;
        /** Set false where clearing is the parent's job. */
        clearable?: boolean;
        class?: string;
    }>(),
    {
        id: undefined,
        disabled: false,
        min: null,
        max: null,
        placeholder: undefined,
        invalid: false,
        ariaLabel: undefined,
        describedBy: undefined,
        clearable: true,
        class: undefined,
    },
);

const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>();

const open = ref(false);

/**
 * A date that reached here from a query string or a server payload is a string
 * somebody could have typed, so parsing is guarded: `parseDate` throws on
 * anything that is not a calendar date, and a filter carrying `?from=lol` must
 * render an empty control rather than break the bar it sits in.
 */
function toCalendarDate(value: string | null | undefined): CalendarDate | null {
    if (typeof value !== 'string' || value === '') {
        return null;
    }

    try {
        return parseDate(value.slice(0, 10));
    } catch {
        return null;
    }
}

const selected = computed(() => toCalendarDate(props.modelValue) ?? undefined);

const minValue = computed(() => toCalendarDate(props.min) ?? undefined);
const maxValue = computed(() => toCalendarDate(props.max) ?? undefined);

const draft = ref('');
const draftInvalid = ref(false);
let pending: string | null | undefined;
watch(
    () => props.modelValue,
    (value) => {
        if (pending !== undefined && value === pending) {
            pending = undefined;
            return;
        }
        pending = undefined;
        draft.value = toCalendarDate(value)?.toString() ?? '';
        draftInvalid.value = false;
    },
    { immediate: true },
);

function publish(value: string | null): void {
    pending = value;
    emit('update:modelValue', value);
}

function onInput(value: string | number): void {
    draft.value = String(value);
    const text = draft.value.trim();
    const date = /^\d{4}-\d{2}-\d{2}$/.test(text) ? toCalendarDate(text) : null;
    draftInvalid.value =
        text !== '' &&
        (date === null ||
            (minValue.value !== undefined &&
                date.compare(minValue.value) < 0) ||
            (maxValue.value !== undefined && date.compare(maxValue.value) > 0));
    publish(draftInvalid.value || text === '' ? null : text);
}

const showClear = computed(
    () => props.clearable && !props.disabled && draft.value !== '',
);

function onSelect(value: unknown): void {
    if (value === undefined || value === null) {
        onClear();

        return;
    }

    // `CalendarDate.toString()` is already `YYYY-MM-DD`, and deliberately not
    // routed through a `Date`: converting to one applies a timezone, and a
    // date picked as the 1st can arrive at the server as the 31st.
    draft.value = String(value);
    draftInvalid.value = false;
    publish(draft.value);
    open.value = false;
}

function onClear(): void {
    draft.value = '';
    draftInvalid.value = false;
    publish(null);
    open.value = false;
}
</script>

<template>
    <div :class="cn('relative', props.class)">
        <Input
            :id="id"
            :model-value="draft"
            :disabled="disabled"
            :placeholder="placeholder ?? 'YYYY-MM-DD'"
            :aria-label="ariaLabel ?? t('forms.date')"
            :aria-describedby="describedBy"
            :aria-invalid="invalid || draftInvalid ? true : undefined"
            class="h-9 pr-16 font-normal tabular-nums"
            autocomplete="off"
            @update:model-value="onInput"
            @keydown.alt.down.prevent="open = !disabled"
        />
        <Popover v-model:open="open">
            <PopoverTrigger as-child>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="absolute top-0 right-0 size-9 text-muted-foreground"
                    :disabled="disabled"
                    :aria-label="t('forms.pick_a_date')"
                >
                    <CalendarIcon class="size-4" />
                </Button>
            </PopoverTrigger>
            <PopoverContent class="w-auto p-0" align="start">
                <Calendar
                    :locale="locale"
                    :model-value="selected"
                    :min-value="minValue"
                    :max-value="maxValue"
                    initial-focus
                    @update:model-value="onSelect"
                />
            </PopoverContent>
        </Popover>

        <!--
            Outside the trigger button rather than inside it: a button nested in
            a button is invalid HTML, and the browser drops the inner one — so
            the clear would render and never fire.
        -->
        <button
            v-if="showClear"
            type="button"
            class="absolute top-1/2 right-10 -translate-y-1/2 rounded-sm text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            :aria-label="t('forms.clear_date')"
            @click="onClear"
        >
            <X class="size-3.5" />
        </button>
    </div>
</template>
