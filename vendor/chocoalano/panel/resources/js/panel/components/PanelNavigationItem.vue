<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { usePanel } from '@/panel/composables/usePanel';
import { resolveIcon } from '@/panel/icons/registry';
import type { NavigationItem } from '@/panel/types/navigation';

const props = defineProps<{
    item: NavigationItem;
}>();

const { panel } = usePanel();

/**
 * The active icon is sent with every item, so the swap happens on a
 * client-side navigation without waiting for the server to say which item
 * won. It falls back to the ordinary icon on the server, so an item that
 * declared only one still has one here.
 */
const icon = computed(() =>
    resolveIcon(props.item.active ? props.item.activeIcon : props.item.icon),
);
const hasBadge = computed(
    () => props.item.badge !== null && props.item.badge !== '',
);

/**
 * A full-page destination renders a plain anchor: it has to leave the SPA,
 * and prefetching it would fetch a document the client cannot use.
 */
const prefetch = computed(() => panel.value?.prefetch ?? false);
</script>

<template>
    <SidebarMenuItem>
        <SidebarMenuButton
            as-child
            :is-active="item.active"
            :tooltip="item.label"
        >
            <a
                v-if="item.fullPage"
                :href="item.href"
                :aria-current="item.active ? 'page' : undefined"
                :aria-label="item.label"
            >
                <component :is="icon" v-if="icon" />
                <span>{{ item.label }}</span>
            </a>
            <Link
                v-else
                :href="item.href"
                :prefetch="prefetch"
                :aria-current="item.active ? 'page' : undefined"
                :aria-label="item.label"
            >
                <component :is="icon" v-if="icon" />
                <span>{{ item.label }}</span>
            </Link>
        </SidebarMenuButton>

        <SidebarMenuBadge v-if="hasBadge">{{ item.badge }}</SidebarMenuBadge>

        <SidebarMenuSub v-if="item.children.length > 0">
            <SidebarMenuSubItem
                v-for="child in item.children"
                :key="child.href"
            >
                <SidebarMenuSubButton as-child :is-active="child.active">
                    <a
                        v-if="child.fullPage"
                        :href="child.href"
                        :aria-current="child.active ? 'page' : undefined"
                    >
                        <span>{{ child.label }}</span>
                    </a>
                    <Link
                        v-else
                        :href="child.href"
                        :prefetch="prefetch"
                        :aria-current="child.active ? 'page' : undefined"
                    >
                        <span>{{ child.label }}</span>
                    </Link>
                </SidebarMenuSubButton>
            </SidebarMenuSubItem>
        </SidebarMenuSub>
    </SidebarMenuItem>
</template>
