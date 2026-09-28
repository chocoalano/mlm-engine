import type { PanelBreadcrumbItem } from '@/panel/types/breadcrumb';
import type { NavigationItem } from '@/panel/types/navigation';

/**
 * Metadata every panel page ships alongside its own props.
 *
 * `headerActions` stays `unknown[]` until the action system defines its
 * serialized shape. Typing it as `unknown` rather than `any` keeps the
 * compiler honest at the point of use.
 */
export interface PageMetadata {
    title: string;
    heading: string;
    subheading: string | null;
    breadcrumbs: PanelBreadcrumbItem[];
    headerActions: unknown[];
    /**
     * What a render hook's scope is matched against: `resource:{slug}` or
     * `page:{slug}`. A slug, never a class name.
     */
    scope: string | null;
    /**
     * The links between one record's pages. Empty on a page that has no
     * record, and on a record with only one page it can reach.
     */
    subNavigation: PageSubNavigation;
    /**
     * The sub-navigation of the cluster this page belongs to.
     *
     * Null for a page in no cluster, and null too when the cluster has
     * nothing this user may see — so a bar with no links in it is never
     * rendered.
     */
    cluster: ClusterNavigation | null;
}

export type ClusterPosition = 'header' | 'right-bar' | 'sidebar';

export interface ClusterNavigation {
    label: string;
    icon: string | null;
    position: ClusterPosition;
    items: NavigationItem[];
}

export type SubNavigationPosition = 'top' | 'start' | 'end';

export interface SubNavigationItem {
    key: string;
    label: string;
    href: string;
    icon: string | null;
    active: boolean;
}

export interface PageSubNavigation {
    items: SubNavigationItem[];
    position: SubNavigationPosition;
}
