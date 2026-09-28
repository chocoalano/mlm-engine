<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { SlidersHorizontal } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import FormComponentRenderer from '@/panel/forms/FormComponentRenderer.vue';
import type { FormDefinition, FormValue, FormValues } from '@/panel/types/form';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

/**
 * The controls a widget or a dashboard is filtered by.
 *
 * The values go into the query string rather than into a request body, for
 * the reason table state does: a filtered dashboard is a link somebody can
 * send, and the back button means what it says. The server narrows whatever
 * arrives through the schema that declared it, so the URL is a request, not a
 * source of truth.
 *
 * `namespace` is what keeps two of these apart. A dashboard's filters live
 * under `filters[...]`; a widget's live under `widgets[{id}][...]`, which is
 * the same arrangement a relation manager's table state uses and for the same
 * reason.
 */
const props = defineProps<{
    form: FormDefinition;
    /** The query-string prefix these values are written under. */
    namespace: string;
    /** Opens in a dialog rather than sitting above the widget. */
    inModal?: boolean;
    title?: string;
}>();

const open = ref(false);

/**
 * Read out of the schema the server sent, which already holds the values it
 * resolved — so what is on screen is what was applied, not what was asked
 * for.
 */
function initialValues(): FormValues {
    const values: FormValues = {};

    for (const node of props.form.schema) {
        if (node.component === 'field') {
            values[node.name] = (node.value ?? null) as FormValue;
        }
    }

    return values;
}

const values = ref<FormValues>(initialValues());

const errors = computed<Record<string, string>>(() => ({}));

/**
 * Applied on change rather than behind a button.
 *
 * A filter with an apply step is right for a form of eight controls, which is
 * what the modal is for; for one or two, the extra click is the whole
 * interaction.
 */
function onChange(name: string, value: FormValue): void {
    values.value = { ...values.value, [name]: value };

    const url = new URL(window.location.href);

    for (const [key, entry] of Object.entries(values.value)) {
        const parameter = `${props.namespace}[${key}]`;

        if (entry === null || entry === '' || entry === false) {
            url.searchParams.delete(parameter);

            continue;
        }

        url.searchParams.set(parameter, String(entry));
    }

    // Back to the first page: a filtered list showing page four of the
    // unfiltered one is a page of nothing.
    url.searchParams.delete('page');

    router.visit(url.pathname + url.search, {
        preserveScroll: true,
        preserveState: true,
    });
}
</script>

<template>
    <div v-if="!inModal" class="flex flex-wrap items-end gap-3">
        <FormComponentRenderer
            v-for="(node, index) in form.schema"
            :key="index"
            :node="node"
            :values="values"
            :errors="errors"
            class="min-w-40"
            @change="onChange"
        />
    </div>

    <template v-else>
        <Button variant="outline" size="sm" class="gap-2" @click="open = true">
            <SlidersHorizontal class="size-4" />
            {{ t('widgets.filters') }}
        </Button>

        <Dialog v-model:open="open">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{{
                        title ?? t('widgets.filters')
                    }}</DialogTitle>
                </DialogHeader>

                <div class="flex flex-col gap-4">
                    <FormComponentRenderer
                        v-for="(node, index) in form.schema"
                        :key="index"
                        :node="node"
                        :values="values"
                        :errors="errors"
                        @change="onChange"
                    />
                </div>
            </DialogContent>
        </Dialog>
    </template>
</template>
