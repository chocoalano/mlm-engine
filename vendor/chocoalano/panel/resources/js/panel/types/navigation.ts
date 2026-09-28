/**
 * Mirrors `PandaPanel\Support\NavigationItem` and `NavigationGroup`.
 *
 * `icon` is a registry key resolved through the panel icon registry, never a
 * component path. `active` is decided on the server; the client does not
 * recompute it.
 */
export interface NavigationItem {
    label: string;
    href: string;
    icon: string | null;
    /**
     * The icon worn while this item is the active one.
     *
     * Sent whether or not it currently is, so the shell can swap icons on a
     * client-side navigation without waiting for the server to say which
     * item won. Falls back to `icon` on the server, so it is never null when
     * `icon` is not.
     */
    activeIcon: string | null;
    badge: string | number | null;
    active: boolean;
    sort: number;
    /** Declared by the panel: this destination needs a real browser navigation. */
    fullPage: boolean;
    children: NavigationItem[];
}

export interface NavigationGroup {
    label: string | null;
    sort: number;
    collapsible: boolean;
    /**
     * The group this one nests under, or null for a top-level group.
     *
     * A nested group is still one group with one set of items; the sidebar
     * draws it indented under its parent rather than as a second heading.
     */
    parent: string | null;
    items: NavigationItem[];
}
