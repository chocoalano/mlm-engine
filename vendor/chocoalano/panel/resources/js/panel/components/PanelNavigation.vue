<script setup lang="ts">
import { ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import {
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarMenu,
} from '@/components/ui/sidebar';
import PanelNavigationItem from '@/panel/components/PanelNavigationItem.vue';
import { useNavigation } from '@/panel/composables/useNavigation';
import type { NavigationGroup } from '@/panel/types/navigation';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

const { groups, isCollapsed, toggle } = useNavigation();

/**
 * A nested group is drawn indented under its parent rather than as a second
 * heading at the top level.
 *
 * Ordering already came from the server, so this only decides indentation —
 * and a group whose declared parent is not on screen (because everything in
 * it was refused) sits at the top level rather than disappearing with it.
 */
const parents = computed(
    () => new Set(groups.value.map((group) => group.label).filter(Boolean)),
);

function isNested(group: NavigationGroup): boolean {
    return group.parent !== null && parents.value.has(group.parent);
}
</script>

<template>
    <nav :aria-label="t('shell.panel_navigation')">
        <SidebarGroup
            v-for="group in groups"
            :key="group.label ?? '__ungrouped__'"
            :class="isNested(group) ? 'ml-2 border-l pl-2' : ''"
        >
            <SidebarGroupLabel v-if="group.label && !group.collapsible">
                {{ group.label }}
            </SidebarGroupLabel>

            <SidebarGroupLabel v-else-if="group.label" as-child>
                <button
                    type="button"
                    class="flex w-full items-center justify-between rounded-md outline-none focus-visible:ring-2 focus-visible:ring-sidebar-ring"
                    :aria-expanded="!isCollapsed(group)"
                    @click="toggle(group)"
                >
                    <span>{{ group.label }}</span>
                    <ChevronRight
                        class="size-3.5 transition-transform duration-200"
                        :class="{ 'rotate-90': !isCollapsed(group) }"
                    />
                </button>
            </SidebarGroupLabel>

            <SidebarGroupContent v-show="!isCollapsed(group)">
                <SidebarMenu>
                    <PanelNavigationItem
                        v-for="item in group.items"
                        :key="item.href"
                        :item="item"
                    />
                </SidebarMenu>
            </SidebarGroupContent>
        </SidebarGroup>
    </nav>
</template>
