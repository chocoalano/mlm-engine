<script setup lang="ts">
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import FieldWrapper from '@/panel/forms/fields/FieldWrapper.vue';
import { uploadFile, useUploadUrl } from '@/panel/forms/uploadEndpoint';
import type { FileUploadFieldDefinition } from '@/panel/types/form';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

const props = defineProps<{
    field: FileUploadFieldDefinition;
    modelValue: unknown;
    error?: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string | string[] | null];
}>();

/**
 * Files are stored before the form is submitted, and the field holds the
 * paths that came back.
 *
 * Two requests rather than one, deliberately. A form submit that carried
 * files would have to be told where to put them; here the server decides
 * from the field's own declaration, and the submit only ever sees a path it
 * previously issued — which is what `FileUpload::accepts()` then re-checks
 * before it reaches a record.
 */
const uploadUrl = useUploadUrl();

const uploading = ref(0);
const failure = ref<string | null>(null);

const paths = computed<string[]>(() => {
    if (Array.isArray(props.modelValue)) {
        return props.modelValue.map(String);
    }

    return typeof props.modelValue === 'string' && props.modelValue !== ''
        ? [props.modelValue]
        : [];
});

const atLimit = computed(
    () =>
        !props.field.multiple ||
        (props.field.maxFiles !== null &&
            paths.value.length >= props.field.maxFiles),
);

const accept = computed(() =>
    props.field.acceptedTypes.length > 0
        ? props.field.acceptedTypes.join(',')
        : undefined,
);

function preview(path: string): string | null {
    return props.field.previewBase === null
        ? null
        : `${props.field.previewBase}/${path}`;
}

/** The last segment, which is what a person recognises in a list. */
function displayName(path: string): string {
    return path.split('/').pop() ?? path;
}

function commit(next: string[]): void {
    emit('update:modelValue', props.field.multiple ? next : (next[0] ?? null));
}

async function onFiles(event: Event): Promise<void> {
    const input = event.target;

    if (!(input instanceof HTMLInputElement) || input.files === null) {
        return;
    }

    const url = uploadUrl();

    if (url === null) {
        failure.value = t('forms.uploads_unavailable');

        return;
    }

    failure.value = null;

    const selected = Array.from(input.files);

    // The input is cleared straight away so picking the same file twice in a
    // row still fires a change event.
    input.value = '';

    for (const file of selected) {
        // Checked here as well as on the server, so an oversized file is
        // refused before it is uploaded rather than after.
        if (file.size > props.field.maxSize * 1024) {
            failure.value = `${file.name} is larger than ${props.field.maxSize} KB.`;

            continue;
        }

        uploading.value += 1;

        const stored = await uploadFile(url, props.field.name, file);

        uploading.value -= 1;

        if (stored === null) {
            failure.value = `${file.name} could not be uploaded.`;

            continue;
        }

        commit(
            props.field.multiple
                ? [...paths.value, stored.path]
                : [stored.path],
        );

        if (!props.field.multiple) {
            return;
        }
    }
}

/**
 * Removes the path from the form only.
 *
 * The stored file is left alone: the form has not been submitted, the record
 * may still be pointing at it, and deleting on a change the user then
 * abandons would destroy the file the record still uses.
 */
function remove(path: string): void {
    commit(paths.value.filter((entry) => entry !== path));
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
        <div class="flex flex-col gap-3">
            <ul v-if="paths.length > 0" class="flex flex-col gap-2">
                <li
                    v-for="path in paths"
                    :key="path"
                    class="flex items-center gap-3 rounded-md border border-input p-2"
                >
                    <img
                        v-if="field.image && preview(path)"
                        :src="preview(path) ?? undefined"
                        :alt="displayName(path)"
                        class="size-10 shrink-0 rounded object-cover"
                    />
                    <a
                        v-else-if="preview(path)"
                        :href="preview(path) ?? undefined"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="truncate text-sm underline underline-offset-4"
                    >
                        {{ displayName(path) }}
                    </a>
                    <span v-else class="truncate text-sm">
                        {{ displayName(path) }}
                    </span>

                    <span
                        v-if="field.image && preview(path)"
                        class="truncate text-sm"
                    >
                        {{ displayName(path) }}
                    </span>

                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="ml-auto shrink-0"
                        :disabled="field.disabled"
                        :aria-label="
                            t('forms.remove_file', { file: displayName(path) })
                        "
                        @click="remove(path)"
                    >
                        {{ t('forms.remove') }}
                    </Button>
                </li>
            </ul>

            <div class="flex items-center gap-3">
                <input
                    :id="controlId"
                    :aria-describedby="describedBy"
                    type="file"
                    class="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border file:border-input file:bg-background file:px-3 file:py-1.5 file:text-sm file:text-foreground hover:file:bg-accent disabled:cursor-not-allowed disabled:opacity-50"
                    :multiple="field.multiple"
                    :accept="accept"
                    :disabled="field.disabled || (atLimit && paths.length > 0)"
                    :aria-invalid="invalid"
                    @change="onFiles"
                />
                <Spinner v-if="uploading > 0" class="size-4 shrink-0" />
            </div>

            <p v-if="failure" class="text-sm text-destructive">{{ failure }}</p>
        </div>
    </FieldWrapper>
</template>
