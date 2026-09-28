<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, defineAsyncComponent } from 'vue';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import { Toaster } from '@/components/ui/sonner';
import PanelClusterBar from '@/panel/components/PanelClusterBar.vue';
import PanelHeader from '@/panel/components/PanelHeader.vue';
import PanelHeaderNavigation from '@/panel/components/PanelHeaderNavigation.vue';
import PanelRenderHook from '@/panel/components/PanelRenderHook.vue';
import { useNavigation } from '@/panel/composables/useNavigation';
import { usePanel } from '@/panel/composables/usePanel';
import { usePanelBranding } from '@/panel/composables/usePanelBranding';
import { usePanelPage } from '@/panel/composables/usePanelPage';
import { usePanelStyling } from '@/panel/composables/usePanelStyling';
import { resolveIcon } from '@/panel/icons/registry';
import { resolveShellComponent } from '@/panel/shell/registry';
import type { PanelBreadcrumbItem } from '@/panel/types/breadcrumb';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

withDefaults(
    defineProps<{
        breadcrumbs?: PanelBreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const { panel, maxContentWidthClass, shell } = usePanel();
const { themeStyle, hook } = usePanelStyling();
const { groups } = useNavigation();
const pageMetadata = usePanelPage();
const { iconName, logo } = usePanelBranding(() => panel.value);

/**
 * A panel may replace the top bar entirely.
 *
 * A build-time registry key, never markup. An unregistered name falls back
 * to the built-in bar rather than leaving the page with no navigation at
 * all — a mistyped component must not strand somebody on a page they cannot
 * leave.
 */
const topbar = computed(() => {
    const name = shell.value.topbarComponent;

    if (name === null) {
        return null;
    }

    const loader = resolveShellComponent(name);

    return loader === null ? null : defineAsyncComponent(loader);
});

const cluster = computed(() => pageMetadata.value?.cluster ?? null);

const brandIcon = computed(() => resolveIcon(iconName.value));
const homeHref = computed(() => `/${panel.value?.path ?? ''}`);

/**
 * Top navigation is a single row, so groups are flattened. Group labels stay
 * out of the bar: they are a sidebar affordance and would only add noise
 * here.
 */
const topLevelItems = computed(() =>
    groups.value.flatMap((group) => group.items),
);
</script>

<template>
    <AppShell variant="header" :class="hook('shell')" :style="themeStyle">
        <PanelRenderHook name="body.start" />

        <component :is="topbar" v-if="topbar" :groups="groups" />

        <div
            v-else-if="shell.navigation"
            class="border-b border-sidebar-border/70 bg-sidebar text-sidebar-foreground"
        >
            <div
                class="mx-auto flex h-16 w-full items-center gap-6 px-4 lg:px-6"
                :class="maxContentWidthClass"
            >
                <Link
                    :href="homeHref"
                    class="flex items-center gap-2 font-semibold"
                >
                    <img
                        v-if="logo"
                        :src="logo"
                        alt=""
                        class="size-5 object-contain"
                    />
                    <component
                        :is="brandIcon"
                        v-else-if="brandIcon"
                        class="size-5"
                    />
                    <span>{{ panel?.brandName }}</span>
                </Link>

                <nav
                    :aria-label="t('shell.panel_navigation')"
                    class="flex items-center gap-1 overflow-x-auto"
                >
                    <PanelHeaderNavigation
                        v-for="item in topLevelItems"
                        :key="item.href"
                        :item="item"
                    />
                </nav>
            </div>
        </div>

        <AppContent variant="header">
            <PanelHeader
                v-if="shell.topbar"
                :breadcrumbs="breadcrumbs"
                :show-sidebar-trigger="false"
            />
            <div
                class="flex w-full flex-1 flex-col gap-4 px-4 py-4 md:gap-6 md:py-6 lg:px-6"
                :class="hook('page')"
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
