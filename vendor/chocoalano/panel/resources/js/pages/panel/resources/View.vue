<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ActionButton from '@/panel/actions/ActionButton.vue';
import ActionModal from '@/panel/actions/ActionModal.vue';
import PageHeader from '@/panel/components/PageHeader.vue';
import PanelRecordLayout from '@/panel/components/PanelRecordLayout.vue';
import { useInfolistActions } from '@/panel/composables/useInfolistActions';
import InfolistRenderer from '@/panel/infolists/InfolistRenderer.vue';
import RelationManagerList from '@/panel/relations/RelationManagerList.vue';
import type { ActionDefinition, ActionEndpoints } from '@/panel/types/action';
import type { InfolistDefinition } from '@/panel/types/infolist';
import type { PageMetadata } from '@/panel/types/page';
import type { RelationDefinition } from '@/panel/types/relation';
import type { ResourceMeta } from '@/panel/types/table';
import type { WidgetData, WidgetDefinition } from '@/panel/types/widget';
import PageWidgets from '@/panel/widgets/PageWidgets.vue';
import PanelLayout from '@/panel/layouts/PanelLayout.vue';

defineOptions({ layout: PanelLayout });

const props = withDefaults(
    defineProps<{
        page: PageMetadata;
        resource: ResourceMeta;
        recordKey: string | number;
        entries: Array<{ label: string; value: string | null }>;
        actionEndpoints: ActionEndpoints;
        /** Null for a resource that has not declared an infolist yet. */
        infolist?: InfolistDefinition | null;
        /** The record's relation managers; empty when it declares none. */
        relations?: RelationDefinition[];
        /** Placed by the page above and below its own content. */
        headerWidgets?: WidgetDefinition[];
        footerWidgets?: WidgetDefinition[];
        /** Deferred: absent from the first response, so optional. */
        widgetData?: WidgetData | null;
    }>(),
    {
        infolist: null,
        relations: () => [],
        headerWidgets: () => [],
        footerWidgets: () => [],
        widgetData: null,
    },
);

const headerActions = props.page.headerActions as ActionDefinition[];

/**
 * A view page's actions are the infolist's own. They run through their own
 * endpoint rather than the table's, because they are a different whitelist —
 * an action shown here must not be runnable from a list that never offered
 * it.
 */
const { pending, processing, formUrl, formContext, run, confirm, cancel } =
    useInfolistActions(
        () => props.resource.slug,
        () => props.actionEndpoints,
        () => props.recordKey,
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
            <PageHeader :heading="page.heading" :subheading="page.subheading">
                <template #actions>
                    <ActionButton
                        v-for="action in headerActions"
                        :key="action.name"
                        :action="action"
                        size="default"
                    />
                </template>
            </PageHeader>
        </template>

        <PageWidgets :widgets="headerWidgets" :widget-data="widgetData" />

        <InfolistRenderer v-if="infolist" :infolist="infolist" @run="run" />

        <dl v-else class="divide-y rounded-lg border">
            <div
                v-for="entry in entries"
                :key="entry.label"
                class="grid gap-1 px-4 py-3 sm:grid-cols-3 sm:gap-4"
            >
                <dt class="text-sm text-muted-foreground">{{ entry.label }}</dt>
                <dd class="text-sm sm:col-span-2">
                    <span v-if="entry.value">{{ entry.value }}</span>
                    <span v-else class="text-muted-foreground">—</span>
                </dd>
            </div>
        </dl>

        <RelationManagerList
            :relations="relations"
            :resource="resource.slug"
            :record="recordKey"
        />

        <PageWidgets :widgets="footerWidgets" :widget-data="widgetData" />

        <ActionModal
            :action="pending"
            :processing="processing"
            :form-url="formUrl"
            :context="formContext"
            @confirm="confirm"
            @cancel="cancel"
            @saved="cancel"
            @run="run"
        />
    </PanelRecordLayout>
</template>
