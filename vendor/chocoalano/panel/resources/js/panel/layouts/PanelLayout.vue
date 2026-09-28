<script setup lang="ts">
import { computed } from 'vue';
import { useContractCheck } from '@/panel/composables/useContractCheck';
import { useErrorNotifications } from '@/panel/composables/useErrorNotifications';
import { usePanel } from '@/panel/composables/usePanel';
import { usePanelBroadcasting } from '@/panel/composables/usePanelBroadcasting';
import { usePanelPage } from '@/panel/composables/usePanelPage';
import HeaderPanelLayout from '@/panel/layouts/HeaderPanelLayout.vue';
import SidebarPanelLayout from '@/panel/layouts/SidebarPanelLayout.vue';
import type { PanelBreadcrumbItem } from '@/panel/types/breadcrumb';

const props = withDefaults(
    defineProps<{
        breadcrumbs?: PanelBreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const { panel } = usePanel();
const pageMetadata = usePanelPage();

// Registered on the shell rather than per page, so one listener covers every
// panel route and is torn down when the panel is left.
useErrorNotifications();
usePanelBroadcasting();

// Says so once, in development, when this published frontend is older than
// the backend serving it — the drift that otherwise fails silently.
useContractCheck();

/**
 * The shell variant is panel configuration, not a page concern, so pages
 * never choose it. Falls back to the sidebar shell when no panel is present,
 * which can happen for the frame of a navigation that leaves the panel.
 */
const shell = computed(() =>
    panel.value?.sidebar.variant === 'header'
        ? HeaderPanelLayout
        : SidebarPanelLayout,
);

/**
 * Breadcrumbs come from the page's own metadata by default, so a page never
 * has to wire them up. An explicit prop wins for the rare page that builds
 * its trail client-side.
 */
const breadcrumbs = computed(() =>
    props.breadcrumbs.length > 0
        ? props.breadcrumbs
        : (pageMetadata.value?.breadcrumbs ?? []),
);
</script>

<template>
    <component :is="shell" :breadcrumbs="breadcrumbs">
        <slot />
    </component>
</template>
