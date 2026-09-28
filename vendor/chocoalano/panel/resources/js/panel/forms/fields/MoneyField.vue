<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import FieldWrapper from '@/panel/forms/fields/FieldWrapper.vue';
import {
    moneyFormat,
    readTyped,
    readValue,
    retype,
    sameAmount,
    stepOverSeparator,
    writeAmount,
} from '@/panel/forms/money';
import type { MoneyFieldDefinition } from '@/panel/types/form';
import { useTranslator } from '@/composables/useTranslator';

const props = defineProps<{
    field: MoneyFieldDefinition;
    modelValue: unknown;
    error?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>();

const { locale } = useTranslator();

const format = computed(() =>
    moneyFormat({
        locale: props.field.locale ?? locale.value,
        currency: props.field.currency,
        decimals: props.field.decimals,
        // A minus is only worth accepting when the server would.
        negative: props.field.min === null || props.field.min < 0,
    }),
);

const placeholder = computed(
    () =>
        props.field.placeholder ??
        writeAmount(
            { negative: false, integer: '0', fraction: null },
            format.value,
            true,
        ),
);

/**
 * What is on screen, which is not the value: `12.500,` is a perfectly good
 * thing to have typed on the way to `12.500,50`, and the value it stands for
 * is `12500`.
 */
const text = ref('');

/**
 * The last value this control emitted.
 *
 * When the form hands that same amount back, the text is left alone — a
 * rewrite from the value would eat the trailing separator somebody just
 * typed. Anything else is a change from outside (a reset, a live rebuild,
 * another field's hook) and replaces the text.
 */
let emitted: string | null | undefined;

function show(value: unknown): void {
    const amount = readValue(value);

    text.value = amount === null ? '' : writeAmount(amount, format.value, true);
}

watch(
    () => props.modelValue,
    (value) => {
        if (emitted !== undefined && sameAmount(value, emitted)) {
            return;
        }

        emitted = undefined;
        show(value);
    },
    { immediate: true },
);

// A locale switch rewrites the separators whatever was typed. Watched by what
// it writes rather than by object: a live rebuild sends a new, identical
// field definition, and rewriting the text mid-keystroke for that would pad
// `12,5` to `12,50` under somebody's cursor.
watch(
    () => {
        const { locale, group, decimal, decimals, symbol } = format.value;

        return [locale, group, decimal, decimals, symbol].join('|');
    },
    () => show(props.modelValue),
);

function publish(value: string | null): void {
    if (
        emitted !== undefined
            ? value === emitted
            : sameAmount(value, props.modelValue)
    ) {
        return;
    }

    emitted = value;
    emit('update:modelValue', value);
}

function onInput(event: Event): void {
    const input = event.target as HTMLInputElement;
    const next = retype(
        input.value,
        input.selectionStart ?? input.value.length,
        format.value,
    );

    // Written to the element as well as to the ref: when a keystroke changes
    // nothing — a letter, a second separator — the ref does not change either,
    // Vue has nothing to patch, and the rejected character would stay.
    input.value = next.text;
    text.value = next.text;
    input.setSelectionRange(next.caret, next.caret);

    publish(next.value);
}

function onKeydown(event: KeyboardEvent): void {
    const input = event.target as HTMLInputElement;
    const caret = input.selectionStart;

    if (
        caret === null ||
        caret !== input.selectionEnd ||
        (event.key !== 'Backspace' && event.key !== 'Delete')
    ) {
        return;
    }

    const next = stepOverSeparator(
        input.value,
        caret,
        event.key === 'Backspace' ? 'backward' : 'forward',
        format.value,
    );

    input.setSelectionRange(next, next);
}

/** Every decimal place once somebody is done: `12.500,5` reads `12.500,50`. */
function onBlur(): void {
    const amount = readTyped(text.value, format.value);

    text.value =
        amount.integer === '' && amount.fraction === null
            ? ''
            : writeAmount(amount, format.value, true);
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
        <!--
            The symbol is drawn beside the input rather than typed into it, so
            the input holds only the number and nobody has to delete "Rp" to
            correct a digit. It sits before or after as the locale writes it,
            and describes the input, so a screen reader hears the currency
            that sighted users see.
        -->
        <div
            :data-invalid="invalid ? '' : undefined"
            :data-disabled="field.disabled ? '' : undefined"
            class="flex h-9 w-full min-w-0 items-center rounded-md border border-input bg-transparent shadow-xs transition-[color,box-shadow] focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50 data-[disabled]:cursor-not-allowed data-[disabled]:opacity-50 data-[invalid]:border-destructive data-[invalid]:ring-destructive/20 dark:bg-input/30 dark:data-[invalid]:ring-destructive/40"
        >
            <span
                v-if="format.symbolFirst"
                :id="`${controlId}-currency`"
                class="pl-3 text-sm whitespace-nowrap text-muted-foreground select-none"
            >
                {{ format.symbol }}
            </span>
            <input
                :id="controlId"
                type="text"
                :inputmode="field.decimals > 0 ? 'decimal' : 'numeric'"
                autocomplete="off"
                :value="text"
                :placeholder="placeholder"
                :disabled="field.disabled"
                :aria-invalid="invalid"
                :aria-describedby="
                    [describedBy, `${controlId}-currency`]
                        .filter(Boolean)
                        .join(' ')
                "
                class="h-full w-full min-w-0 bg-transparent px-3 py-1 text-base tabular-nums outline-none placeholder:text-muted-foreground disabled:cursor-not-allowed md:text-sm"
                @input="onInput"
                @keydown="onKeydown"
                @blur="onBlur"
            />
            <span
                v-if="!format.symbolFirst"
                :id="`${controlId}-currency`"
                class="pr-3 text-sm whitespace-nowrap text-muted-foreground select-none"
            >
                {{ format.symbol }}
            </span>
        </div>
    </FieldWrapper>
</template>
