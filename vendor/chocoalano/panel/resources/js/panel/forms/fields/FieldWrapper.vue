<script setup lang="ts">
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import {
    useFieldIdentity,
    useFieldRegistration,
} from '@/panel/forms/fieldIdentity';

/**
 * The label, the helper, the error, and the control they belong to.
 *
 * All four were already rendered here and none of them were connected. The
 * label pointed at `field.name`, the helper and the error had no ids at all,
 * and a control could therefore say `aria-invalid` without being able to say
 * *what* was invalid about it. Somebody using a screen reader heard the label
 * and then silence — the sentence explaining the format, and the sentence
 * explaining the refusal, were both on screen and neither was announced.
 *
 * The ids come from one derivation (`useFieldIdentity`) and are handed to the
 * control through the slot, so the wrapper and the control cannot disagree
 * about what the control is called.
 */
const props = defineProps<{
    name: string;
    label: string;
    required: boolean;
    helperText: string | null;
    error?: string;
    /** Renders the label after the control, for checkboxes and toggles. */
    inline?: boolean;
    /**
     * Renders the label beside the control rather than above it, for a form
     * that reads as a list of settings. Ignored when `inline` already puts
     * the label after the control — a checkbox's label belongs next to the
     * box whichever way the rest of the form is laid out.
     */
    inlineLabel?: boolean;
    /**
     * Labels a set of controls rather than one.
     *
     * A radio group and a checkbox list have no single element to point a
     * `<label for>` at, so the label names a `role="group"` instead and the
     * description hangs off the group. Set by those fields; the ordinary
     * single-control case leaves it alone.
     */
    group?: boolean;
}>();

const identity = useFieldIdentity(() => props.name, {
    helper: () => props.helperText !== null && props.helperText !== '',
    error: () => props.error !== undefined && props.error !== '',
});

useFieldRegistration(identity, () => props.label);

const invalid = computed(() => props.error !== undefined && props.error !== '');

const labelId = computed(() => `${identity.value.controlId}-label`);

/** What the slot hands the control, and the only thing it should bind. */
const slotProps = computed(() => ({
    controlId: identity.value.controlId,
    describedBy: identity.value.describedBy,
    invalid: invalid.value ? true : undefined,
    required: props.required ? true : undefined,
    /** For a group: the label names the set rather than one control. */
    labelledBy: props.group ? labelId.value : undefined,
    /**
     * The label's own id, for a control whose name `<label for>` cannot
     * reach — a combobox trigger is labelled "Show popup" by reka-ui, and
     * that wins over the `<label>` unless something names it explicitly.
     */
    labelId: labelId.value,
}));
</script>

<template>
    <div v-if="inline" class="flex items-start gap-3">
        <slot v-bind="slotProps" />
        <div class="flex flex-col gap-1">
            <Label
                :id="labelId"
                :for="group ? undefined : identity.controlId"
                class="font-normal"
            >
                {{ label }}
                <span v-if="required" class="text-destructive">*</span>
            </Label>
            <p
                v-if="helperText"
                :id="identity.helperId"
                class="text-xs text-muted-foreground"
            >
                {{ helperText }}
            </p>
            <InputError :id="identity.errorId" :message="error" />
        </div>
    </div>

    <div
        v-else-if="inlineLabel"
        class="grid grid-cols-1 items-start gap-x-4 gap-y-1.5 sm:grid-cols-[12rem_1fr]"
    >
        <Label
            :id="labelId"
            :for="group ? undefined : identity.controlId"
            class="sm:pt-2"
        >
            {{ label }}
            <span v-if="required" class="text-destructive">*</span>
        </Label>
        <div class="flex flex-col gap-1.5">
            <slot v-bind="slotProps" />
            <p
                v-if="helperText"
                :id="identity.helperId"
                class="text-xs text-muted-foreground"
            >
                {{ helperText }}
            </p>
            <InputError :id="identity.errorId" :message="error" />
        </div>
    </div>

    <div v-else class="flex flex-col gap-1.5">
        <Label :id="labelId" :for="group ? undefined : identity.controlId">
            {{ label }}
            <span v-if="required" class="text-destructive">*</span>
        </Label>
        <slot v-bind="slotProps" />
        <p
            v-if="helperText"
            :id="identity.helperId"
            class="text-xs text-muted-foreground"
        >
            {{ helperText }}
        </p>
        <InputError :id="identity.errorId" :message="error" />
    </div>
</template>
