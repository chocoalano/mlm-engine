<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import PanelNavigation from '@/panel/components/PanelNavigation.vue';
import PanelRenderHook from '@/panel/components/PanelRenderHook.vue';
import { usePanel } from '@/panel/composables/usePanel';
import { usePanelBranding } from '@/panel/composables/usePanelBranding';
import { usePanelStyling } from '@/panel/composables/usePanelStyling';
import { resolveIcon } from '@/panel/icons/registry';
import type { PanelSidebarAppearance } from '@/panel/types/panel';

const APPEARANCES: readonly PanelSidebarAppearance[] = [
    'sidebar',
    'floating',
    'inset',
];

const DEFAULT_APPEARANCE: PanelSidebarAppearance = 'inset';

const { panel } = usePanel();
const { hook } = usePanelStyling();
const { iconName, logo } = usePanelBranding(() => panel.value);

const brandIcon = computed(() => resolveIcon(iconName.value));
const homeHref = computed(() => `/${panel.value?.path ?? ''}`);
const collapsible = computed(() =>
    panel.value?.sidebar.collapsible === false ? 'none' : 'icon',
);

/**
 * The panel chooses the rail's appearance. Validated rather than asserted, so
 * an unknown value from the server falls back instead of reaching the shadcn
 * component as an unhandled variant.
 */
const appearance = computed<PanelSidebarAppearance>(() => {
    const value = panel.value?.sidebar.appearance;

    return value !== undefined && APPEARANCES.includes(value)
        ? value
        : DEFAULT_APPEARANCE;
});
</script>

<template>
    <!--
        Widths are custom properties rather than classes: a class built by
        interpolation would not exist in the bundle, and a rail's width is a
        length a panel states in `rem` rather than one of four sizes somebody
        wrote out in advance.
    -->
    <Sidebar
        :class="hook('sidebar')"
        :collapsible="collapsible"
        :variant="appearance"
        :style="{
            '--sidebar-width': panel?.sidebar.width ?? '16rem',
            '--sidebar-width-icon': panel?.sidebar.collapsedWidth ?? '3rem',
        }"
    >
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="homeHref">
                            <div
                                class="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground"
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
                                    class="size-4"
                                />
                            </div>
                            <div
                                class="grid flex-1 text-left text-sm leading-tight"
                            >
                                <span class="truncate font-semibold">
                                    {{ panel?.brandName }}
                                </span>
                                <span
                                    class="truncate text-xs text-sidebar-foreground/70"
                                >
                                    {{ panel?.name }}
                                </span>
                            </div>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <PanelRenderHook name="sidebar.start" />
            <PanelNavigation />
            <PanelRenderHook name="sidebar.end" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
</template>
