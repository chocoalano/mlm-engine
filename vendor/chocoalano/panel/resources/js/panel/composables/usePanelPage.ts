import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import type { PanelBreadcrumbItem } from '@/panel/types/breadcrumb';
import type { NavigationItem } from '@/panel/types/navigation';
import type {
    ClusterNavigation,
    ClusterPosition,
    PageMetadata,
    PageSubNavigation,
    SubNavigationItem,
    SubNavigationPosition,
} from '@/panel/types/page';

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function toBreadcrumb(value: unknown): PanelBreadcrumbItem | null {
    if (!isRecord(value) || typeof value.label !== 'string') {
        return null;
    }

    return {
        label: value.label,
        href: typeof value.href === 'string' ? value.href : null,
        current: value.current === true,
    };
}

const POSITIONS: readonly SubNavigationPosition[] = ['top', 'start', 'end'];

function toSubNavigationItem(value: unknown): SubNavigationItem | null {
    if (
        !isRecord(value) ||
        typeof value.key !== 'string' ||
        typeof value.label !== 'string' ||
        typeof value.href !== 'string'
    ) {
        return null;
    }

    return {
        key: value.key,
        label: value.label,
        href: value.href,
        icon: typeof value.icon === 'string' ? value.icon : null,
        active: value.active === true,
    };
}

/**
 * Absent on every page that has no record, which is most of them, so this
 * degrades to "no sub-navigation" rather than treating it as a shape error.
 */
function toSubNavigation(value: unknown): PageSubNavigation {
    const position =
        isRecord(value) &&
        typeof value.position === 'string' &&
        POSITIONS.includes(value.position as SubNavigationPosition)
            ? (value.position as SubNavigationPosition)
            : 'top';

    const items =
        isRecord(value) && Array.isArray(value.items)
            ? value.items
                  .map(toSubNavigationItem)
                  .filter((item): item is SubNavigationItem => item !== null)
            : [];

    return { items, position };
}

/**
 * Every panel page ships a `page` prop with its own metadata, so the layout
 * can render the header and breadcrumbs without each page wiring them up.
 *
 * The prop is validated rather than cast: it crosses the PHP/TypeScript
 * boundary, so a shape mismatch should degrade to a bare page instead of
 * throwing inside the layout.
 */
export function normalizePageMetadata(value: unknown): PageMetadata | null {
    if (!isRecord(value) || typeof value.heading !== 'string') {
        return null;
    }

    const breadcrumbs = Array.isArray(value.breadcrumbs)
        ? value.breadcrumbs
              .map(toBreadcrumb)
              .filter((crumb): crumb is PanelBreadcrumbItem => crumb !== null)
        : [];

    return {
        title: typeof value.title === 'string' ? value.title : value.heading,
        heading: value.heading,
        subheading:
            typeof value.subheading === 'string' ? value.subheading : null,
        breadcrumbs,
        headerActions: Array.isArray(value.headerActions)
            ? value.headerActions
            : [],
        // Validated like everything else crossing the boundary: an absent
        // scope means the page matches only unscoped render hooks.
        scope: typeof value.scope === 'string' ? value.scope : null,
        subNavigation: toSubNavigation(value.subNavigation),
        cluster: toCluster(value.cluster),
    };
}

const CLUSTER_POSITIONS: readonly ClusterPosition[] = [
    'header',
    'right-bar',
    'sidebar',
];

/**
 * A cluster's sub-navigation, narrowed like everything else that crosses.
 *
 * A position the shell has no place for falls back to the header rather than
 * reaching a branch that does not exist.
 */
function toCluster(value: unknown): ClusterNavigation | null {
    if (!isRecord(value) || typeof value.label !== 'string') {
        return null;
    }

    const items = Array.isArray(value.items)
        ? (value.items as NavigationItem[])
        : [];

    if (items.length === 0) {
        return null;
    }

    return {
        label: value.label,
        icon: typeof value.icon === 'string' ? value.icon : null,
        position:
            CLUSTER_POSITIONS.find(
                (candidate) => candidate === value.position,
            ) ?? 'header',
        items,
    };
}

export function usePanelPage(): ComputedRef<PageMetadata | null> {
    const page = usePage();

    return computed(() => normalizePageMetadata(page.props.page));
}
