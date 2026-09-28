<script setup lang="ts">
import { computed, defineAsyncComponent, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Sheet, SheetContent } from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import ActionButton from '@/panel/actions/ActionButton.vue';
import { usePanelStyling } from '@/panel/composables/usePanelStyling';
import FormRenderer from '@/panel/forms/FormRenderer.vue';
import { resolveFormComponent } from '@/panel/forms/registry';
import type {
    ActionDefinition,
    ModalDefinition,
    ModalWidth,
} from '@/panel/types/action';
import type { FormDefinition } from '@/panel/types/form';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

/**
 * The one dialog every action opens.
 *
 * Three kinds of content, and they compose rather than exclude each other: a
 * confirmation, a component of this application's own, and the action's form.
 * An action that declares a form *and* content shows the content above the
 * form, which is how "here is what this will do" ends up beside the fields
 * that decide it.
 *
 * A slide-over is the same content in a different place. It is a `Sheet`
 * rather than a `Dialog` because that is what changes — the panel it renders
 * in — and nothing inside has to know which one it is in.
 */
const props = defineProps<{
    action: ActionDefinition | null;
    processing: boolean;
    /** Where the action's form is fetched from, when it has one. */
    formUrl: string | null;
    /** Extra values posted with the form, naming what the action is about. */
    context: Record<string, unknown>;
}>();

const emit = defineEmits<{
    confirm: [];
    cancel: [];
    saved: [];
    run: [action: ActionDefinition];
}>();

/**
 * Widths map to literal classes. An interpolated `sm:max-w-${width}` would
 * compile to nothing, which is why the server's width is a closed enum.
 */
const WIDTH_CLASSES: Record<ModalWidth, string> = {
    sm: 'sm:max-w-sm',
    md: 'sm:max-w-md',
    lg: 'sm:max-w-lg',
    xl: 'sm:max-w-xl',
    '2xl': 'sm:max-w-2xl',
    '4xl': 'sm:max-w-4xl',
    screen: 'sm:max-w-[95vw]',
};

const DEFAULTS: ModalDefinition = {
    width: 'md',
    slideOver: false,
    stickyHeader: false,
    stickyFooter: false,
    closeByClickingAway: true,
    closeByEscaping: true,
    autofocus: true,
    heading: null,
    description: null,
    submitLabel: null,
    cancelLabel: null,
    cancel: true,
    componentName: null,
    config: {},
};

interface ActionFormPayload {
    title: string;
    submitLabel: string;
    form: FormDefinition;
    submitUrl: string;
    method: 'post' | 'put';
    /**
     * Where a searchable select on this form asks for options beyond its first
     * page, carrying the action's own context. Null when the server sent none.
     */
    optionsUrl: string | null;
    /**
     * Where a file field on this form uploads to, carrying the action's own
     * context so the server authorizes the upload as the action rather than
     * as the resource. Null when the server did not send one.
     */
    uploadUrl: string | null;
    /**
     * Where a `live()` field on this form asks what the form should look like
     * now. Its absence is why `live()` used to work on a resource's create and
     * edit pages and quietly do nothing inside an action's dialog.
     */
    formStateUrl: string | null;
    context: Record<string, unknown>;
}

const payload = ref<ActionFormPayload | null>(null);
const loading = ref(false);
const failed = ref(false);

const modal = computed<ModalDefinition>(() => props.action?.modal ?? DEFAULTS);

const open = computed(() => props.action !== null);

const heading = computed(
    () =>
        modal.value.heading ??
        props.action?.confirmation?.heading ??
        payload.value?.title ??
        props.action?.label ??
        '',
);

const description = computed(
    () => modal.value.description ?? props.action?.confirmation?.description,
);

/**
 * A dialog with a form submits through the form; one without submits through
 * its own button. Two different things called "submit", so only one is ever
 * rendered.
 */
const hasForm = computed(() => props.formUrl !== null);

const content = computed(() => {
    const name = modal.value.componentName;

    if (name === null) {
        return null;
    }

    const loader = resolveFormComponent(name);

    return loader === null ? null : defineAsyncComponent(loader);
});

/**
 * Narrowed rather than asserted: this crosses from the server as untyped JSON
 * like every other payload, and a shape that does not match must leave the
 * dialog visibly empty instead of throwing inside the renderer.
 */
function toPayload(value: unknown): ActionFormPayload | null {
    if (typeof value !== 'object' || value === null) {
        return null;
    }

    const candidate = value as Partial<ActionFormPayload>;

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
        context:
            typeof candidate.context === 'object' && candidate.context !== null
                ? (candidate.context as Record<string, unknown>)
                : {},
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

        payload.value = response.ok ? toPayload(await response.json()) : null;
        failed.value = payload.value === null;
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

function onOpenChange(next: boolean): void {
    if (!next) {
        emit('cancel');
    }
}

/**
 * A dialog told not to close on a click outside or on Escape does exactly
 * that. Both are ordinary escapes from a modal, and turning them off is only
 * worth it where the cost of losing what was typed is higher — which is the
 * caller's judgement, made on the server.
 */
function onInteractOutside(event: Event): void {
    if (!modal.value.closeByClickingAway) {
        event.preventDefault();
    }
}

function onEscape(event: KeyboardEvent): void {
    if (!modal.value.closeByEscaping) {
        event.preventDefault();
    }
}

function onOpenAutoFocus(event: Event): void {
    if (!modal.value.autofocus) {
        event.preventDefault();
    }
}

const { hook } = usePanelStyling();
</script>

<template>
    <component
        :is="modal.slideOver ? Sheet : Dialog"
        :open="open"
        @update:open="onOpenChange"
    >
        <component
            :is="modal.slideOver ? SheetContent : DialogContent"
            :class="[
                'flex max-h-[90vh] flex-col overflow-hidden',
                modal.slideOver
                    ? 'w-full sm:max-w-xl'
                    : WIDTH_CLASSES[modal.width],
                hook('modal'),
            ]"
            @interact-outside="onInteractOutside"
            @escape-key-down="onEscape"
            @open-auto-focus="onOpenAutoFocus"
        >
            <DialogHeader
                :class="
                    modal.stickyHeader
                        ? 'sticky top-0 z-10 bg-background pb-2'
                        : ''
                "
            >
                <DialogTitle>{{ heading }}</DialogTitle>
                <DialogDescription v-if="description">
                    {{ description }}
                </DialogDescription>
                <DialogDescription v-else-if="failed">
                    {{ t('forms.load_failed') }}
                </DialogDescription>
            </DialogHeader>

            <div class="flex-1 overflow-y-auto">
                <!--
                    Custom content renders above the form. An unregistered
                    component renders nothing rather than throwing, so one
                    mistyped name cannot take the dialog down with it.
                -->
                <component
                    :is="content"
                    v-if="content"
                    :config="modal.config"
                    :action="action"
                    class="mb-4"
                />

                <div v-if="loading" class="flex justify-center py-8">
                    <Spinner class="size-6" />
                </div>

                <!--
                    Keyed by the submit URL and the action, so opening the
                    dialog for a second record remounts the renderer rather
                    than showing the first one's values.
                -->
                <FormRenderer
                    v-else-if="hasForm && payload"
                    :key="`${payload.submitUrl}:${action?.name}`"
                    :form="payload.form"
                    :submit-url="payload.submitUrl"
                    :submit-label="payload.submitLabel"
                    :options-url="payload.optionsUrl"
                    :upload-url="payload.uploadUrl"
                    :form-state-url="payload.formStateUrl"
                    :context="{ ...payload.context, ...context }"
                    :method="payload.method"
                    @saved="emit('saved')"
                />
            </div>

            <DialogFooter
                v-if="!hasForm"
                :class="
                    modal.stickyFooter
                        ? 'sticky bottom-0 z-10 bg-background pt-2'
                        : ''
                "
            >
                <!--
                    Actions registered on this dialog. They run through the
                    same endpoints their trigger would, and are reachable only
                    because their parent declared them.
                -->
                <ActionButton
                    v-for="registered in action?.modalActions ?? []"
                    :key="registered.name"
                    :action="registered"
                    :disabled="processing"
                    @run="(next) => emit('run', next)"
                />

                <Button
                    v-if="modal.cancel"
                    variant="ghost"
                    :disabled="processing"
                    @click="emit('cancel')"
                >
                    {{ modal.cancelLabel ?? t('actions.cancel') }}
                </Button>
                <Button
                    v-if="action?.confirmation || action?.type === 'callback'"
                    :variant="action?.variant"
                    :disabled="processing"
                    @click="emit('confirm')"
                >
                    <Spinner v-if="processing" class="size-4" />
                    {{
                        modal.submitLabel ??
                        action?.confirmation?.button ??
                        action?.label
                    }}
                </Button>
            </DialogFooter>
        </component>
    </component>
</template>
