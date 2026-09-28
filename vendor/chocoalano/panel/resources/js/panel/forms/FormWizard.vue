<script setup lang="ts">
import { Check } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import FormComponentRenderer from '@/panel/forms/FormComponentRenderer.vue';
import { csrfToken } from '@/panel/forms/http';
import { resolveIcon } from '@/panel/icons/registry';
import type {
    FormValue,
    FormValues,
    WizardDefinition,
} from '@/panel/types/form';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

/**
 * A form shown one step at a time.
 *
 * Stepping is presentation: the whole form is submitted at once and the
 * server validates all of it. When something comes back rejected, the wizard
 * jumps to the first step holding a rejected field, so an error is never
 * hidden behind a step the user has walked past.
 */
const props = defineProps<{
    wizard: WizardDefinition;
    values: FormValues;
    errors: Record<string, string>;
    processing: boolean;
    /** Null when the page did not offer per-step validation. */
    validateStepUrl?: string | null;
}>();

const emit = defineEmits<{
    change: [name: string, value: FormValue];
    submit: [];
    stepErrors: [errors: Record<string, string>];
}>();

const current = ref(0);
const checking = ref(false);

const steps = computed(() => props.wizard.steps);
const isLast = computed(() => current.value >= steps.value.length - 1);

function stepHasError(index: number): boolean {
    return (steps.value[index]?.fields ?? []).some(
        (name) => props.errors[name] !== undefined,
    );
}

/**
 * A rejected field the user cannot see is a dead end, so the first step
 * holding one wins whenever the errors change.
 */
watch(
    () => props.errors,
    (errors) => {
        if (Object.keys(errors).length === 0) {
            return;
        }

        const index = steps.value.findIndex((_, position) =>
            stepHasError(position),
        );

        if (index !== -1) {
            current.value = index;
        }
    },
    { deep: true },
);

function go(index: number): void {
    current.value = Math.min(Math.max(index, 0), steps.value.length - 1);
}

/**
 * Asks the server whether this step is acceptable before moving on.
 *
 * The rules are the form's own, narrowed to the fields this step already
 * says it holds — there is no second definition that could disagree with the
 * final submit. Without a URL the wizard steps freely and everything is
 * checked at the end, which is what a page that did not offer this wants.
 */
async function next(): Promise<void> {
    const url = props.validateStepUrl;

    if (!url) {
        go(current.value + 1);

        return;
    }

    checking.value = true;

    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({ ...props.values, step: current.value }),
        });

        if (response.ok) {
            emit('stepErrors', {});
            go(current.value + 1);

            return;
        }

        const payload: unknown = await response.json();
        const errors =
            typeof payload === 'object' && payload !== null
                ? ((payload as { errors?: unknown }).errors ?? {})
                : {};

        emit('stepErrors', flatten(errors));
    } catch {
        // A step that cannot be checked should not trap the user: the final
        // submit validates everything anyway.
        go(current.value + 1);
    } finally {
        checking.value = false;
    }
}

/**
 * Laravel sends an array of messages per field; the form shows one.
 */
function flatten(errors: unknown): Record<string, string> {
    if (typeof errors !== 'object' || errors === null) {
        return {};
    }

    const flat: Record<string, string> = {};

    for (const [field, messages] of Object.entries(errors)) {
        const message = Array.isArray(messages) ? messages[0] : messages;

        if (typeof message === 'string') {
            flat[field] = message;
        }
    }

    return flat;
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <ol class="flex flex-wrap items-center gap-2">
            <li v-for="(step, index) in steps" :key="step.label">
                <button
                    type="button"
                    class="flex items-center gap-2 rounded-md px-3 py-2 text-sm transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :class="[
                        index === current
                            ? 'bg-accent font-medium text-accent-foreground'
                            : 'text-muted-foreground',
                        stepHasError(index) ? 'text-destructive' : '',
                    ]"
                    :aria-current="index === current ? 'step' : undefined"
                    @click="go(index)"
                >
                    <span
                        class="flex size-6 items-center justify-center rounded-full border text-xs tabular-nums"
                    >
                        <Check v-if="index < current" class="size-3" />
                        <component
                            :is="resolveIcon(step.icon)"
                            v-else-if="resolveIcon(step.icon)"
                            class="size-3"
                        />
                        <template v-else>{{ index + 1 }}</template>
                    </span>
                    <span>{{ step.label }}</span>
                </button>
            </li>
        </ol>

        <div
            v-for="(step, index) in steps"
            v-show="index === current"
            :key="step.label"
            class="flex flex-col gap-4"
        >
            <p v-if="step.description" class="text-sm text-muted-foreground">
                {{ step.description }}
            </p>

            <FormComponentRenderer
                v-for="(node, nodeIndex) in step.schema"
                :key="nodeIndex"
                :node="node"
                :values="values"
                :errors="errors"
                @change="(name, value) => emit('change', name, value)"
            />
        </div>

        <div class="flex items-center gap-2">
            <Button
                v-if="current > 0"
                type="button"
                variant="ghost"
                :disabled="processing"
                @click="go(current - 1)"
            >
                {{ t('forms.back') }}
            </Button>

            <Button
                v-if="!isLast"
                type="button"
                :disabled="processing || checking"
                @click="next()"
            >
                <Spinner v-if="checking" class="size-4" />
                {{ t('forms.next') }}
            </Button>

            <Button
                v-else
                type="button"
                :disabled="processing"
                @click="emit('submit')"
            >
                <Spinner v-if="processing" class="size-4" />
                {{ wizard.submitLabel }}
            </Button>
        </div>
    </div>
</template>
