<script setup lang="ts">
import type { FormDataConvertible, RequestPayload } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { usePanelStyling } from '@/panel/composables/usePanelStyling';
import {
    createFieldRegistry,
    provideFieldRegistry,
} from '@/panel/forms/fieldIdentity';
import { focusFirstInvalid } from '@/panel/forms/focusInvalid';
import { useUnsavedChangesAlert } from '@/panel/composables/useUnsavedChangesAlert';
import FormGrid from '@/panel/forms/FormGrid.vue';
import {
    fetchFormState,
    provideFormStateUrl,
} from '@/panel/forms/formStateEndpoint';
import FormWizard from '@/panel/forms/FormWizard.vue';
import {
    provideFormValues,
    provideOptionsUrl,
} from '@/panel/forms/optionsEndpoint';
import { provideUploadUrl } from '@/panel/forms/uploadEndpoint';
import { validateFields } from '@/panel/forms/validation';
import type {
    FieldDefinition,
    FormComponentDefinition,
    FormDefinition,
    FormValue,
    FormValues,
} from '@/panel/types/form';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

const props = defineProps<{
    form: FormDefinition;
    submitUrl: string;
    method?: 'post' | 'put';
    submitLabel?: string;
    cancelUrl?: string;
    /** Offers a second submit that returns to an empty form. */
    createAnotherLabel?: string;
    /** Per-step validation endpoint, for a stepped form that has one. */
    validateStepUrl?: string | null;
    /** Where a searchable select asks for options beyond its first page. */
    optionsUrl?: string | null;
    /** Where a file field stores its file. */
    uploadUrl?: string | null;
    /** Where a live field asks what the form should look like now. */
    formStateUrl?: string | null;
    /**
     * Values posted alongside the form's own.
     *
     * An action's form submits to a shared endpoint that has to be told which
     * action, on which resource, for which record — none of which is a field
     * somebody fills in. Kept apart from the values so it cannot collide with
     * one and cannot be edited into something else.
     */
    context?: Record<string, unknown>;
    /**
     * Pins the submit row to the bottom of the viewport.
     *
     * On for a full-page record form, where the form is long enough that the
     * buttons would otherwise be a scroll away. Off inside a dialog, which
     * has a footer of its own.
     */
    stickyActions?: boolean;
}>();

// Provided rather than passed down: a field can sit four layouts deep, and
// every one of them would otherwise carry a prop it does not use.
provideOptionsUrl(() => props.optionsUrl ?? null);
provideUploadUrl(() => props.uploadUrl ?? null);
provideFormStateUrl(() => props.formStateUrl ?? null);

/**
 * Every field registers itself here as it renders, so a rejected submit can
 * find the control an error belongs to — including one inside a repeater,
 * whose DOM id is nothing like the path the server keyed the message by.
 */
const registry = createFieldRegistry();

provideFieldRegistry(registry);

/**
 * `saved` lets a host close itself once the write went through. A page
 * ignores it — the server has already redirected it somewhere — and a dialog
 * uses it, which is the whole difference between the two.
 */
const emit = defineEmits<{ saved: [] }>();

/**
 * The schema currently on screen.
 *
 * Local rather than read straight from the prop, because a `live()` field can
 * have the server rebuild it: new options for a dependent select, a different
 * set of visible components. The prop is still the source of truth for a new
 * form — a fresh page, or a dialog reopened on another record — which is what
 * the watch below restores.
 */
const schema = ref<FormComponentDefinition[]>(props.form.schema);

watch(
    () => props.form,
    (form) => {
        schema.value = form.schema;
    },
);

/**
 * Collects every field in the schema, however deeply nested, so the initial
 * values come from the same definitions that render the inputs.
 */
function collectFields(nodes: FormComponentDefinition[]): FieldDefinition[] {
    return nodes.flatMap((node) => {
        if (node.component === 'field') {
            return [node];
        }

        // A wizard and a tab set nest their fields one level deeper.
        if (node.component === 'wizard') {
            return node.steps.flatMap((step) => collectFields(step.schema));
        }

        if (node.component === 'tabs') {
            return node.tabs.flatMap((tab) => collectFields(tab.schema));
        }

        // Prime components and empty states hold no fields at all.
        if (!('schema' in node)) {
            return [];
        }

        return collectFields(node.schema);
    });
}

const fields = computed(() => collectFields(schema.value));

/**
 * Field values cross the wire as JSON, so they are narrowed once here
 * instead of being asserted wherever they are read.
 *
 * A repeater holds a list of maps and a key-value field holds a map, so
 * "narrowed" means "is JSON" rather than "is a scalar" — anything that is
 * not, such as a function or an undefined, becomes null.
 */
function toFormValue(value: unknown): FormValue {
    if (
        typeof value === 'string' ||
        typeof value === 'number' ||
        typeof value === 'boolean'
    ) {
        return value;
    }

    if (Array.isArray(value)) {
        return value.map(toFormValue);
    }

    if (typeof value === 'object' && value !== null) {
        const map: FormValues = {};

        for (const [key, entry] of Object.entries(value)) {
            map[key] = toFormValue(entry);
        }

        return map;
    }

    return null;
}

/**
 * Expands dotted field names into the nested data Laravel validates.
 *
 * A relation group names its fields `profile.bio` and a pivot names its
 * `pivot.role`. Those are single flat keys in the working values — which is
 * what lets an error keyed `profile.bio` land on the field that produced it —
 * but the server has to receive them nested, or the rule looks for a key that
 * is not there.
 */
function expandDotted(values: FormValues): RequestPayload {
    const expanded: Record<string, FormDataConvertible> = {};

    for (const [name, value] of Object.entries(values)) {
        if (!name.includes('.')) {
            expanded[name] = value;

            continue;
        }

        const segments = name.split('.');
        const last = segments.pop() as string;

        let cursor = expanded;

        for (const segment of segments) {
            const next = cursor[segment];

            if (typeof next !== 'object' || next === null) {
                cursor[segment] = {};
            }

            cursor = cursor[segment] as Record<string, FormDataConvertible>;
        }

        cursor[last] = value;
    }

    return expanded;
}

function initialValues(): FormValues {
    const values: FormValues = {};

    for (const field of collectFields(props.form.schema)) {
        values[field.name] = toFormValue(field.value);

        if (field.type === 'password' && field.confirmed) {
            values[`${field.name}_confirmation`] = '';
        }
    }

    return values;
}

/**
 * Working values are local because a form is edited before it is submitted;
 * that is the one place local state is the right answer. Errors are not
 * local: they come back from the server on the Inertia response.
 */
const initial = initialValues();

const values = ref<FormValues>({ ...initial });
const errors = ref<Record<string, string>>({});
const processing = ref(false);

// A dependent select searches within what the rest of the form holds — the
// employees in the chosen department, not every employee. Provided rather
// than passed down for the reason the URLs are: the field asking can be four
// layouts deep, and every layout between would carry a prop it does not use.
provideFormValues(() => values.value);

const label = computed(() => props.submitLabel ?? t('forms.save'));

/**
 * What was refused, in the order it appears on screen.
 *
 * A long form that is rejected used to look like a form that did nothing: the
 * page did not move, and the messages were beside fields that could be a
 * screen away or inside a tab that was not open. This says how many there are
 * and offers a way to each one.
 *
 * The field's *label* rather than its message: the message is already on the
 * field, and repeating all of them here is a second wall of text to read
 * before reaching the first one.
 */
const summary = computed(() => {
    const listed = registry
        .paths()
        .filter((path) => errors.value[path] !== undefined)
        .map((path) => ({
            path,
            label: registry.get(path)?.label ?? path,
            controlId: registry.get(path)?.controlId ?? path,
        }));

    // Anything the registry does not know about is still counted. A message
    // keyed to something that is not a rendered field — a schema-level rule —
    // is not linkable, but pretending it does not exist would make the count
    // disagree with the form.
    const unlisted = Object.keys(errors.value).filter(
        (path) => !listed.some((entry) => entry.path === path),
    );

    return { listed, count: listed.length + unlisted.length };
});

/** Focused after a failed submit, so the summary is where the user lands. */
const summaryRef = ref<HTMLElement | null>(null);

async function reportErrors(): Promise<void> {
    const focused = await focusFirstInvalid(registry, errors.value, document);

    // Nothing could take focus — every invalid field is hidden by a condition,
    // or the errors name nothing rendered. The summary is then the only thing
    // that can tell the user the submit was answered at all.
    if (focused === null) {
        summaryRef.value?.focus();
    }
}

function jumpTo(controlId: string): void {
    const element = document.getElementById(controlId);

    element?.scrollIntoView({ block: 'center' });
    element?.focus();
}

/**
 * A wizard owns the whole form when present: it renders the steps and its
 * own controls, so this component's buttons would be a second way to submit.
 */
const wizard = computed(() =>
    schema.value.length === 1 && schema.value[0].component === 'wizard'
        ? schema.value[0]
        : null,
);

/**
 * Compared against the values the server sent, so returning a field to what
 * it was counts as clean. `processing` suppresses the alert during the
 * form's own submit, which is a navigation like any other.
 */
const isDirty = computed(
    () =>
        !processing.value &&
        JSON.stringify(values.value) !== JSON.stringify(initial),
);

useUnsavedChangesAlert(isDirty);

/*
 * Live fields.
 *
 * A field marked `live()` asks the server to rebuild the schema after it
 * changes — for the dependencies the declarative conditions cannot express,
 * such as a select whose options come from another field. Everything else is
 * evaluated in the browser and costs no request at all.
 */
const timers = new Map<string, ReturnType<typeof setTimeout>>();
const pendingBlur = new Set<string>();
const previousValues = new Map<string, FormValue>();

let inFlight: AbortController | null = null;

onBeforeUnmount(() => {
    for (const timer of timers.values()) {
        clearTimeout(timer);
    }

    inFlight?.abort();
});

async function sendState(name: string): Promise<void> {
    const url = props.formStateUrl ?? null;

    if (url === null) {
        return;
    }

    // Only the latest request matters: an earlier answer arriving second
    // would put the form back to how it looked two keystrokes ago.
    inFlight?.abort();
    inFlight = new AbortController();

    const response = await fetchFormState(
        url,
        values.value,
        name,
        previousValues.get(name) ?? null,
        inFlight.signal,
    );

    previousValues.delete(name);

    if (response === null) {
        return;
    }

    schema.value = response.form.schema;

    // Fields the rebuilt schema introduced need a value to bind to; ones the
    // user has already typed into keep theirs, because the request describes
    // what the form looks like, not what it holds.
    const next = { ...values.value };

    for (const field of collectFields(response.form.schema)) {
        if (!(field.name in next)) {
            next[field.name] = toFormValue(field.value);
        }
    }

    // Except where the server said otherwise. A patch is the one thing that
    // overrides what the user typed, and it is only ever produced by a
    // callback the schema declared — nothing in the request can name one.
    //
    // This is what makes a stale dependent value actually clear. Rebuilding
    // the child's options was never enough on its own: the value stayed in
    // `next` above, so the select went on displaying a choice its own list no
    // longer contained until the submit refused it.
    for (const [path, value] of Object.entries(response.statePatch)) {
        next[path] = toFormValue(value);

        // A patched field is no longer wrong in the way it was, and leaving
        // the error up would point at a value that is gone.
        if (errors.value[path]) {
            const remaining = { ...errors.value };

            delete remaining[path];

            errors.value = remaining;
        }
    }

    values.value = next;
}

function scheduleLive(field: FieldDefinition, previous: FormValue): void {
    if (field.live === null) {
        return;
    }

    if (!previousValues.has(field.name)) {
        previousValues.set(field.name, previous);
    }

    if (field.live.onBlur) {
        // Held until focus leaves the control, so a field that only makes
        // sense once fully typed is not asked about halfway through.
        pendingBlur.add(field.name);

        return;
    }

    const existing = timers.get(field.name);

    if (existing !== undefined) {
        clearTimeout(existing);
    }

    timers.set(
        field.name,
        setTimeout(() => {
            timers.delete(field.name);
            void sendState(field.name);
        }, field.live.debounce),
    );
}

/**
 * One listener on the form catches every blur, so twenty-five renderers do not
 * each have to emit one.
 *
 * The blurred element is turned back into a field through the registry. It
 * used to be read straight off `target.id`, which worked only while the id and
 * the state path were the same string — they are not: a relation group names
 * its fields `profile.bio`, and a field inside a repeater is one control among
 * several with that name.
 */
function onFocusOut(event: FocusEvent): void {
    const target = event.target;

    if (!(target instanceof HTMLElement) || target.id === '') {
        return;
    }

    const path = registry.pathFor(target.id);

    if (path !== null && pendingBlur.delete(path)) {
        void sendState(path);
    }
}

function onChange(name: string, value: unknown): void {
    const previous = values.value[name] ?? null;

    values.value = { ...values.value, [name]: toFormValue(value) };

    // Clear the field's error as soon as it is edited, so a corrected
    // field stops looking wrong before the next round trip.
    if (errors.value[name]) {
        const remaining = { ...errors.value };

        delete remaining[name];

        errors.value = remaining;
    }

    const field = fields.value.find((candidate) => candidate.name === name);

    if (field !== undefined) {
        scheduleLive(field, previous);
    }
}

function submit(createAnother = false): void {
    // Checked here only to save a round trip. The server validates everything
    // again, and anything it can check that a browser cannot — uniqueness,
    // existence — is deliberately not attempted.
    const clientErrors = validateFields(fields.value, values.value);

    if (Object.keys(clientErrors).length > 0) {
        errors.value = clientErrors;

        void reportErrors();

        return;
    }

    processing.value = true;

    const data = {
        ...expandDotted(values.value),
        ...((props.context ?? {}) as RequestPayload),
    };

    router.visit(props.submitUrl, {
        method: props.method ?? 'post',
        // The server decides what "another" means — a blank form or the
        // same values again — so the client only says which button it was.
        data: createAnother ? { ...data, createAnother: true } : data,
        preserveScroll: true,
        onSuccess: () => {
            errors.value = {};
            emit('saved');
        },
        onError: (received) => {
            errors.value = received;

            void reportErrors();
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}

const { hook } = usePanelStyling();
</script>

<template>
    <form
        class="flex flex-col gap-4"
        :class="hook('form')"
        @submit.prevent="submit()"
        @focusout="onFocusOut"
    >
        <!--
            Only after a refusal, and only then. `role="alert"` announces it
            when it appears, and `tabindex="-1"` makes it a place focus can be
            sent when no invalid field can take it.
        -->
        <div
            v-if="summary.count > 0"
            ref="summaryRef"
            role="alert"
            tabindex="-1"
            class="flex flex-col gap-1 rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive"
        >
            <p class="font-medium">
                {{ t('forms.error_summary', { count: summary.count }) }}
            </p>
            <ul v-if="summary.listed.length > 0" class="flex flex-wrap gap-x-3">
                <li v-for="entry in summary.listed" :key="entry.path">
                    <button
                        type="button"
                        class="underline underline-offset-2"
                        @click="jumpTo(entry.controlId)"
                    >
                        {{ entry.label }}
                    </button>
                </li>
            </ul>
        </div>

        <FormWizard
            v-if="wizard"
            :wizard="wizard"
            :values="values"
            :errors="errors"
            :processing="processing"
            :validate-step-url="validateStepUrl"
            @change="onChange"
            @submit="submit()"
            @step-errors="(received) => (errors = received)"
        />

        <!--
            The root is a grid like every other container, so `$schema->
            columns(2)` means at the top level what it means inside a section.
            It used to be sent and ignored: the root stacked its nodes in a
            column and a field that asked for half a row got a whole one, with
            nothing to say why.

            Sections and every other layout still take the full width — they
            are containers, and a half-width section is not what `columns()`
            on the schema was ever asking for — so a form built the usual way
            is laid out exactly as before.
        -->
        <FormGrid
            v-if="!wizard"
            :grid="{ component: 'grid', columns: form.columns, schema }"
            :values="values"
            :errors="errors"
            @change="onChange"
        />

        <!--
            Sticky on a full-page form, in the flow everywhere else.

            An ERP record form is long, and the buttons used to sit at the
            very bottom of it: saving a forty-field form meant scrolling past
            everything to reach Save, and reviewing a field after that meant
            scrolling back. Pinned to the bottom of the viewport, Save is
            wherever the user is.

            Not the default, because this same renderer draws the form inside
            an action dialog, where the dialog already owns its footer and a
            second pinned bar inside it would be two.
        -->
        <!--
            Wrapping, not scrolling. `flex items-center` with three buttons and
            long labels — "Simpan & buat lainnya" is half again the width of
            "Save & create another" — pushed the row past the viewport, and a
            sticky bar cannot be scrolled sideways to reach what fell off it.

            `flex-wrap` rather than a stacked mobile variant: the hierarchy is
            carried by the button variants, and it survives wrapping. Turning
            all three into full-width blocks would make Cancel look as
            important as Save.
        -->
        <div
            v-if="!wizard"
            class="flex flex-wrap items-center gap-2"
            :class="
                stickyActions
                    ? 'panel-form-actions sticky bottom-0 z-10 -mx-1 border-t bg-background px-1 py-3'
                    : ''
            "
        >
            <Button type="submit" :disabled="processing">
                <Spinner v-if="processing" class="size-4" />
                {{ label }}
            </Button>
            <Button
                v-if="createAnotherLabel"
                type="button"
                variant="outline"
                :disabled="processing"
                @click="submit(true)"
            >
                {{ createAnotherLabel }}
            </Button>
            <Button
                v-if="cancelUrl"
                type="button"
                variant="ghost"
                as="a"
                :href="cancelUrl"
            >
                {{ t('forms.cancel') }}
            </Button>
        </div>
    </form>
</template>
