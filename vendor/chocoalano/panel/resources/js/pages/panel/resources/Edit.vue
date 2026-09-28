<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PageHeader from '@/panel/components/PageHeader.vue';
import PanelRecordLayout from '@/panel/components/PanelRecordLayout.vue';
import FormRenderer from '@/panel/forms/FormRenderer.vue';
import RelationManagerList from '@/panel/relations/RelationManagerList.vue';
import type { FormDefinition } from '@/panel/types/form';
import type { PageMetadata } from '@/panel/types/page';
import type { RelationDefinition } from '@/panel/types/relation';
import type { ResourceMeta } from '@/panel/types/table';
import type { WidgetData, WidgetDefinition } from '@/panel/types/widget';
import PageWidgets from '@/panel/widgets/PageWidgets.vue';
import PanelLayout from '@/panel/layouts/PanelLayout.vue';

defineOptions({ layout: PanelLayout });

withDefaults(
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
         * What the submit button says. Null keeps "Save changes", so a page
         * that names none reads exactly as it did.
         */
        submitLabel?: string | null;
        recordKey: string | number;
        /** The record's relation managers; empty when it declares none. */
        relations?: RelationDefinition[];
        /** Placed by the page above and below its own content. */
        headerWidgets?: WidgetDefinition[];
        footerWidgets?: WidgetDefinition[];
        /** Deferred: absent from the first response, so optional. */
        widgetData?: WidgetData | null;
    }>(),
    {
        validateStepUrl: null,
        optionsUrl: null,
        uploadUrl: null,
        formStateUrl: null,
        submitLabel: null,
        relations: () => [],
        headerWidgets: () => [],
        footerWidgets: () => [],
        widgetData: null,
    },
);
</script>

<template>
    <Head :title="page.title" />

    <!--
        A record with relation tables needs the width for them; one without
        reads better narrow. The layout is the same either way.
    -->
    <PanelRecordLayout
        :class="relations.length > 0 ? 'max-w-5xl' : 'max-w-full'"
        :sub-navigation="page.subNavigation"
    >
        <template #header>
            <PageHeader :heading="page.heading" :subheading="page.subheading" />
        </template>

        <PageWidgets :widgets="headerWidgets" :widget-data="widgetData" />

        <FormRenderer
            :key="String(recordKey)"
            :form="form"
            :submit-url="submitUrl"
            :options-url="optionsUrl"
            :upload-url="uploadUrl"
            :form-state-url="formStateUrl"
            :validate-step-url="validateStepUrl"
            method="put"
            :submit-label="submitLabel ?? 'Save changes'"
            :cancel-url="resource.indexUrl"
            sticky-actions
        />

        <RelationManagerList
            :relations="relations"
            :resource="resource.slug"
            :record="recordKey"
        />

        <PageWidgets :widgets="footerWidgets" :widget-data="widgetData" />
    </PanelRecordLayout>
</template>
