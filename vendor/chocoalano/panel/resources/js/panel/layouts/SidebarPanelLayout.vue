<script setup lang="ts">
import { computed, defineAsyncComponent } from 'vue';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import { Toaster } from '@/components/ui/sonner';
import PanelClusterBar from '@/panel/components/PanelClusterBar.vue';
import PanelHeader from '@/panel/components/PanelHeader.vue';
import PanelRenderHook from '@/panel/components/PanelRenderHook.vue';
import PanelSidebar from '@/panel/components/PanelSidebar.vue';
import { usePanel } from '@/panel/composables/usePanel';
import { usePanelPage } from '@/panel/composables/usePanelPage';
import { usePanelStyling } from '@/panel/composables/usePanelStyling';
import { resolveShellComponent } from '@/panel/shell/registry';
import type { PanelBreadcrumbItem } from '@/panel/types/breadcrumb';

withDefaults(
    defineProps<{
        breadcrumbs?: PanelBreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const { maxContentWidthClass, panel, shell } = usePanel();
const { themeStyle, hook } = usePanelStyling();
const pageMetadata = usePanelPage();

/**
 * A panel may replace the rail entirely.
 *
 * The replacement is a build-time registry key, never markup, and it is
 * handed the same navigation the built-in one gets — a different *drawing*
 * of the panel rather than a second source of truth about it. An
 * unregistered name falls back to the built-in rail rather than leaving the
 * page with no navigation at all.
 */
const sidebar = computed(() => {
    const name = panel.value?.sidebar.component ?? null;

    if (name === null) {
        return PanelSidebar;
    }

    const loader = resolveShellComponent(name);

    return loader === null ? PanelSidebar : defineAsyncComponent(loader);
});

const cluster = computed(() => pageMetadata.value?.cluster ?? null);
</script>

<template>
    <!--
        The theme is set here, on the shell root, so every custom property is
        in scope for everything the panel draws — and for nothing outside it.
    -->
    <AppShell variant="sidebar" :class="hook('shell')" :style="themeStyle">
        <PanelRenderHook name="body.start" />

        <!-- A panel that turned navigation off has no rail at all: removed
             rather than hidden, so nothing is rendered and nothing is sent. -->
        <component :is="sidebar" v-if="shell.navigation" />

        <!--
            `overflow-x-clip`, not `overflow-x-hidden`.

            Both stop a wide table from widening the page. But `hidden` on one
            axis computes the other axis to `auto`, which makes this element a
            scroll container — and a scroll container swallows every
            `position: sticky` inside it, because sticky resolves against its
            nearest scrolling ancestor rather than the viewport. That is why
            the selection bar and the form's save row are sticky and *work*:
            `clip` clips without scrolling, so the viewport stays the
            reference.
        -->
        <AppContent variant="sidebar" class="overflow-x-clip">
            <PanelHeader v-if="shell.topbar" :breadcrumbs="breadcrumbs" />
            <!--
                The dashboard block's content rhythm: a vertical gap that grows
                with the viewport, and a gutter that matches the header's.
            -->
            <div
                class="mx-auto flex w-full flex-1 flex-col gap-4 px-4 py-4 md:gap-6 md:py-6 lg:px-6"
                :class="[maxContentWidthClass, hook('page')]"
            >
                <PanelRenderHook name="page.start" />

                <PanelClusterBar
                    v-if="cluster && cluster.position === 'header'"
                    :cluster="cluster"
                    orientation="row"
                />

                <!--
                    Stacked until there is room to sit side by side. The rail
                    is fixed-width, so on a narrow screen a row would give the
                    content about ninety pixels — see `PanelClusterBar`.
                -->
                <div
                    v-if="cluster && cluster.position === 'right-bar'"
                    class="flex flex-1 flex-col gap-4 lg:flex-row lg:gap-6"
                >
                    <div class="flex min-w-0 flex-1 flex-col gap-4 md:gap-6">
                        <slot />
                    </div>
                    <PanelClusterBar :cluster="cluster" orientation="column" />
                </div>

                <slot v-else />

                <PanelRenderHook name="page.end" />
            </div>
        </AppContent>
        <PanelRenderHook name="body.end" />
        <Toaster />
    </AppShell>
</template>
