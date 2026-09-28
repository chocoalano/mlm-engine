<script setup lang="ts">
import { ref, watch } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import FormRenderer from '@/panel/forms/FormRenderer.vue';
import type { FormDefinition } from '@/panel/types/form';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

/**
 * The dialog behind a `form` action.
 *
 * The schema is fetched when the dialog opens rather than shipped with the
 * row: a table of twenty records would otherwise carry twenty filled-in forms
 * to open at most one of them. That also means the form is always current —
 * it is built from the record as it is now, not as it was when the page
 * rendered.
 *
 * Everything inside is `FormRenderer`, the same component the create and edit
 * pages use, so a field renders and validates identically wherever it appears.
 */
const props = defineProps<{
    /** The URL to fetch the form from; null closes the dialog. */
    formUrl: string | null;
}>();

const emit = defineEmits<{ close: [] }>();

interface RelationFormPayload {
    title: string;
    submitLabel: string;
    form: FormDefinition;
    submitUrl: string;
    method: 'post' | 'put';
    optionsUrl: string | null;
    /**
     * Where a file field on this form uploads to. Carries the relation and
     * the operation, so the server asks the relation's own abilities rather
     * than the owning resource's.
     */
    uploadUrl: string | null;
    /**
     * Where a `live()` field on this form asks what the form should look like
     * now. Null when the server did not send one, which leaves live fields
     * behaving as ordinary ones rather than failing.
     */
    formStateUrl: string | null;
}

const payload = ref<RelationFormPayload | null>(null);
const loading = ref(false);
const failed = ref(false);

/**
 * Narrowed rather than asserted: this crosses from the server as untyped
 * JSON like every other payload, and a shape that does not match must leave
 * the dialog visibly empty instead of throwing inside the renderer.
 */
function toPayload(value: unknown): RelationFormPayload | null {
    if (typeof value !== 'object' || value === null) {
        return null;
    }

    const candidate = value as Partial<RelationFormPayload>;

    if (
        typeof candidate.title !== 'string' ||
        typeof candidate.submitLabel !== 'string' ||
        typeof candidate.submitUrl !== 'string' ||
        typeof candidate.form !== 'object' ||
        candidate.form === null ||
        !Array.isArray(candidate.form.schema)
    ) {
        return null;
    }

    return {
        title: candidate.title,
        submitLabel: candidate.submitLabel,
        form: candidate.form,
        submitUrl: candidate.submitUrl,
        method: candidate.method === 'put' ? 'put' : 'post',
        optionsUrl:
            typeof candidate.optionsUrl === 'string'
                ? candidate.optionsUrl
                : null,
        uploadUrl:
            typeof candidate.uploadUrl === 'string'
                ? candidate.uploadUrl
                : null,
        formStateUrl:
            typeof candidate.formStateUrl === 'string'
                ? candidate.formStateUrl
                : null,
    };
}

async function load(url: string): Promise<void> {
    loading.value = true;
    failed.value = false;
    payload.value = null;

    try {
        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        failed.value = !response.ok;

        payload.value = response.ok ? toPayload(await response.json()) : null;
        failed.value = failed.value || payload.value === null;
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.formUrl,
    (url) => {
        if (url === null) {
            payload.value = null;
            failed.value = false;

            return;
        }

        void load(url);
    },
    { immediate: true },
);

function onOpenChange(open: boolean): void {
    if (!open) {
        emit('close');
    }
}
</script>

<template>
    <Dialog :open="formUrl !== null" @update:open="onOpenChange">
        <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>{{
                    payload?.title ?? t('forms.loading')
                }}</DialogTitle>
                <DialogDescription v-if="failed">
                    {{ t('forms.load_failed') }}
                </DialogDescription>
            </DialogHeader>

            <div v-if="loading" class="flex justify-center py-8">
                <Spinner class="size-6" />
            </div>

            <!--
                Keyed by the submit URL so switching from one record's form to
                another remounts the renderer. Without it the second dialog
                would open holding the first record's values.
            -->
            <FormRenderer
                v-else-if="payload"
                :key="payload.submitUrl"
                :form="payload.form"
                :submit-url="payload.submitUrl"
                :method="payload.method"
                :submit-label="payload.submitLabel"
                :options-url="payload.optionsUrl"
                :upload-url="payload.uploadUrl"
                :form-state-url="payload.formStateUrl"
                @saved="emit('close')"
            />
        </DialogContent>
    </Dialog>
</template>
