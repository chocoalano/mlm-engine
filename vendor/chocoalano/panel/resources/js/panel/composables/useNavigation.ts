import { useStorage } from '@vueuse/core';
import { computed } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import { usePanel } from '@/panel/composables/usePanel';
import { panelSharedProps } from '@/panel/types/shared';
import type { NavigationGroup, NavigationItem } from '@/panel/types/navigation';

function flatten(groups: NavigationGroup[]): NavigationItem[] {
    return groups.flatMap((group) =>
        group.items.flatMap((item) => [item, ...item.children]),
    );
}

export type UseNavigationReturn = {
    groups: ComputedRef<NavigationGroup[]>;
    items: ComputedRef<NavigationItem[]>;
    activeItem: ComputedRef<NavigationItem | null>;
    isCollapsed: (group: NavigationGroup) => boolean;
    toggle: (group: NavigationGroup) => void;
};

/**
 * Server-driven sidebar state.
 *
 * The groups themselves are read-only server data and are never copied into
 * local state. The only client-owned bit is which collapsible groups the
 * user has closed, which is persisted per panel so the sidebar looks the
 * same after a reload.
 */
export function useNavigation(): UseNavigationReturn {
    const props = panelSharedProps();
    const { panel } = usePanel();

    const groups = computed(() => props.value.navigation);
    const items = computed(() => flatten(groups.value));
    const activeItem = computed(
        () => items.value.find((item) => item.active) ?? null,
    );

    const storageKey = computed(
        () => `panel:${panel.value?.id ?? 'unknown'}:collapsed-groups`,
    );

    const collapsed: Ref<Record<string, boolean>> = useStorage<
        Record<string, boolean>
    >(storageKey, {}, undefined, { initOnMounted: true });

    function keyFor(group: NavigationGroup): string {
        return group.label ?? '__ungrouped__';
    }

    function isCollapsed(group: NavigationGroup): boolean {
        return group.collapsible && collapsed.value[keyFor(group)] === true;
    }

    function toggle(group: NavigationGroup): void {
        if (!group.collapsible) {
            return;
        }

        const key = keyFor(group);

        collapsed.value = {
            ...collapsed.value,
            [key]: !collapsed.value[key],
        };
    }

    return { groups, items, activeItem, isCollapsed, toggle };
}
