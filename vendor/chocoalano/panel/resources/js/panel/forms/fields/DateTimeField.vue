<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import FieldWrapper from '@/panel/forms/fields/FieldWrapper.vue';
import PanelDatePicker from '@/panel/components/PanelDatePicker.vue';
import PanelTimePicker from '@/panel/components/PanelTimePicker.vue';
import { useTranslator } from '@/composables/useTranslator';
import { timeWithinBounds } from '@/panel/components/temporalValue';
import type { DateTimeFieldDefinition } from '@/panel/types/form';

const { t } = useTranslator();

const props = defineProps<{
    field: DateTimeFieldDefinition;
    modelValue: unknown;
    error?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>();

/**
 * A date and a time, from the panel's own controls.
 *
 * This used to be one `<input type="datetime-local">`. What crosses the
 * boundary has not moved: the server formats with a literal `T`
 * (`2026-08-15T09:30`) and a column would rather hold a space
 * (`2026-08-15 09:30`), so both are accepted coming in and a space is what
 * goes back out. The translation still happens once, here, and neither side
 * has had to change its mind about the format it prefers.
 *
 * What has moved is the control. Two halves are held as separate drafts,
 * because a datetime is two decisions and the state between them is not a
 * datetime — a date with no time is not midnight, it is a question that has
 * not been answered yet, and emitting `00:00` on the user's behalf writes a
 * time nobody chose.
 */
interface Split {
    date: string;
    time: string;
}

/**
 * Splits a stored value into its halves, accepting either separator.
 *
 * Narrowed rather than trusted: the value arrives as JSON from a server
 * payload, and something that is not a datetime must leave the control empty
 * rather than throw inside the renderer.
 */
function split(value: unknown): Split | null {
    if (typeof value !== 'string') {
        return null;
    }

    const match = /^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}(?::\d{2})?)$/.exec(
        value.trim(),
    );

    return match === null
        ? null
        : { date: match[1] as string, time: match[2] as string };
}

/** The chosen calendar date, `YYYY-MM-DD`, or null while none is chosen. */
const date = ref<string | null>(null);

/** The chosen time, `HH:mm[:ss]`, or null while it is incomplete. */
const time = ref<string | null>(null);

let pending: string | null | undefined;

watch(
    () => props.modelValue,
    (value) => {
        if (pending !== undefined && value === pending) {
            pending = undefined;
            return;
        }
        pending = undefined;
        const parts = split(value);

        date.value = parts?.date ?? null;
        time.value = parts?.time ?? null;
    },
    { immediate: true },
);

const minSplit = computed(() => split(props.field.minDate));
const maxSplit = computed(() => split(props.field.maxDate));

/**
 * The calendar bound, which is the date half of the datetime bound.
 *
 * A bound of `2026-09-10 09:30` makes every day before the 10th
 * unselectable and the 10th itself selectable — the 10th at 10:00 satisfies
 * it. Which times on the 10th satisfy it is the next question, and a
 * different one.
 */
const minDate = computed(
    () => minSplit.value?.date ?? props.field.minDate?.slice(0, 10) ?? null,
);
const maxDate = computed(
    () => maxSplit.value?.date ?? props.field.maxDate?.slice(0, 10) ?? null,
);

/**
 * The time bound, which applies only on the boundary day itself.
 *
 * This is the half the old control got wrong. Handing a `datetime-local`
 * input a `min` of `2026-09-10T09:30` did constrain it, but slicing the
 * bound to ten characters and passing it to a calendar does not: the day
 * becomes selectable and every time on it does too, so `2026-09-10 08:00`
 * was reachable under a minimum of `09:30`. The hours are bounded here, and
 * only when the chosen date is the boundary date — on the 11th, every time
 * is legal again.
 */
const minTime = computed(() =>
    minSplit.value !== null && date.value === minSplit.value.date
        ? minSplit.value.time
        : null,
);
const maxTime = computed(() =>
    maxSplit.value !== null && date.value === maxSplit.value.date
        ? maxSplit.value.time
        : null,
);

/**
 * Whether the time currently held is legal for the date currently chosen.
 *
 * Shares `timeWithinBounds` with `PanelTimePicker`, which decides the same
 * thing one option at a time. Two implementations of "is this time inside its
 * bounds" is how a control ends up greying out 09:29 while its own field
 * accepts it.
 */
function withinBounds(value: string): boolean {
    return timeWithinBounds(value, minTime.value, maxTime.value);
}

/** What the field currently amounts to, or null while a half is missing. */
function publish(): void {
    const next =
        date.value === null || time.value === null
            ? null
            : `${date.value} ${time.value}`;

    if (next !== props.modelValue) {
        pending = next;
        emit('update:modelValue', next);
    }
}

/**
 * Changing the date keeps the time, which is the point of holding them apart:
 * moving an appointment from the 10th to the 11th should not make somebody
 * re-enter 10:30.
 *
 * The exception is a time the new date makes illegal — 08:00 is fine on the
 * 11th and not on the 10th when the minimum is `2026-09-10 09:30`. It is
 * cleared rather than nudged to the nearest legal value: silently moving a
 * chosen 08:00 to 09:30 is a decision this field is not entitled to make, and
 * an empty time control says plainly that the answer is needed again.
 */
function onDate(value: string | null): void {
    date.value = value;

    if (time.value !== null && !withinBounds(time.value)) {
        time.value = null;
    }

    publish();
}

function onTime(value: string | null): void {
    time.value = value;
    publish();
}
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
            One group holding two controls: the date and the time are halves
            of a single answer, and a reader moving through the form is told
            that once rather than meeting two fields that happen to sit
            together. It wraps rather than scrolls on a narrow viewport — a
            date trigger and three selects do not fit across 320px, and a
            control that has to be scrolled to is a control that gets missed.
        -->
        <div
            role="group"
            :aria-labelledby="labelledBy"
            :aria-label="
                labelledBy === undefined ? t('forms.date_and_time') : undefined
            "
            class="flex flex-wrap items-center gap-2"
        >
            <PanelDatePicker
                :id="controlId"
                class="w-full sm:min-w-44 sm:flex-1"
                :model-value="date"
                :disabled="field.disabled"
                :min="minDate"
                :max="maxDate"
                :invalid="invalid === true"
                :described-by="describedBy"
                :aria-label="t('forms.date')"
                :clearable="!field.required"
                @update:model-value="onDate"
            />

            <PanelTimePicker
                :id="`${controlId}-time`"
                :model-value="time"
                :seconds="field.seconds"
                :disabled="field.disabled"
                :invalid="invalid"
                :described-by="describedBy"
                :aria-label="t('forms.time')"
                :min-time="minTime"
                :max-time="maxTime"
                :clearable="!field.required"
                @update:model-value="onTime"
            />
        </div>
    </FieldWrapper>
</template>
