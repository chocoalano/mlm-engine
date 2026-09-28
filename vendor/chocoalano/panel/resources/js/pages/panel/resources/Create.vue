<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PageHeader from '@/panel/components/PageHeader.vue';
import FormRenderer from '@/panel/forms/FormRenderer.vue';
import type { FormDefinition } from '@/panel/types/form';
import type { PageMetadata } from '@/panel/types/page';
import type { ResourceMeta } from '@/panel/types/table';
import type { WidgetData, WidgetDefinition } from '@/panel/types/widget';
import PageWidgets from '@/panel/widgets/PageWidgets.vue';
import PanelLayout from '@/panel/layouts/PanelLayout.vue';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

defineOptions({ layout: PanelLayout });

const props = withDefaults(
    defineProps<{
        page: PageMetadata;
        resource: ResourceMeta;
        form: FormDefinition;
        submitUrl: string;
        optionsUrl?: string | null;
        uploadUrl?: string | null;
        formStateUrl?: string | null;
        validateStepUrl?: string | null;
        /**
         * What the submit button says. Null keeps the resource-derived
         * default, so a page that names none reads exactly as it did.
         */
        submitLabel?: string | null;
        canCreateAnother?: boolean;
        /** Placed by the page above and below its own content. */
        headerWidgets?: WidgetDefinition[];
        footerWidgets?: WidgetDefinition[];
        /** Deferred: absent from the first response, so optional. */
        widgetData?: WidgetData | null;
    }>(),
    {
        canCreateAnother: false,
        validateStepUrl: null,
        submitLabel: null,
        optionsUrl: null,
        uploadUrl: null,
        formStateUrl: null,
        headerWidgets: () => [],
        footerWidgets: () => [],
        widgetData: null,
    },
);
</script>

<template>
    <Head :title="page.title" />

    <div class="flex max-w-full flex-col gap-4">
        <PageHeader :heading="page.heading" :subheading="page.subheading" />

        <PageWidgets :widgets="headerWidgets" :widget-data="widgetData" />

        <FormRenderer
            :form="form"
            :submit-url="submitUrl"
            :options-url="optionsUrl"
            :upload-url="uploadUrl"
            :form-state-url="formStateUrl"
            :validate-step-url="validateStepUrl"
            method="post"
            :submit-label="
                submitLabel ??
                t('forms.create_record', { resource: resource.label })
            "
            :create-another-label="
                props.canCreateAnother ? t('forms.create_another') : undefined
            "
            :cancel-url="resource.indexUrl"
            sticky-actions
        />

        <PageWidgets :widgets="footerWidgets" :widget-data="widgetData" />
    </div>
</template>
