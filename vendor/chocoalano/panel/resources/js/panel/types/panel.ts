import type { NavigationGroup } from '@/panel/types/navigation';

/**
 * Mirrors `PandaPanel\Core\Panel::toSharedArray()`.
 *
 * Both props are absent outside a panel route, so `panel` is nullable and
 * `navigation` is empty rather than undefined.
 */
export interface PanelDefinition {
    id: string;
    name: string;
    path: string;
    brandName: string;
    brandLogo: string | null;
    darkBrandLogo: string | null;
    icon: string | null;
    darkIcon: string | null;
    favicon: string | null;
    darkFavicon: string | null;
    darkMode: boolean;
    maxContentWidth: string | null;
    /** Server-side settings stay on the server; only these are acted on here. */
    unsavedChangesAlerts: boolean;
    prefetch: PanelPrefetchMode | null;
    /** Keyed by HTTP status. A null entry means show nothing for it. */
    errorNotifications: Record<string, PanelErrorNotification | null>;
    renderHooks: Partial<Record<PanelRenderHookName, PanelRenderHookEntry[]>>;
    sidebar: PanelSidebarSettings;
    shell: PanelShellSettings;
    /**
     * The panel's colours, as CSS custom properties.
     *
     * Values rather than meanings: a meaning (`success`, `danger`) is a
     * closed enum because each maps to a literal class, and a value never
     * becomes a class at all.
     */
    theme: PanelThemeColors;
    /**
     * Extra classes on named parts of the shell, keyed by hook name. Every
     * part already carries a stable `panel-{name}` class; these are added to
     * it.
     */
    cssHooks: Record<string, string>;
}

export interface PanelThemeColors {
    light: Record<string, string>;
    dark: Record<string, string>;
}

/**
 * What the shell draws and what it leaves out.
 *
 * A panel with one page has nothing to navigate; a kiosk has no use for
 * breadcrumbs. Turning one off removes it rather than hiding it.
 */
export interface PanelShellSettings {
    navigation: boolean;
    topbar: boolean;
    breadcrumbs: boolean;
    /** A build-time registry key replacing the shell's own topbar. */
    topbarComponent: string | null;
    userMenuItems: PanelUserMenuItem[];
}

export interface PanelUserMenuItem {
    label: string;
    /** A link the server produced, never an action name to resolve. */
    url: string;
    icon: string | null;
}

/**
 * `variant` mirrors the starter kit's own AppVariant, so the panel shell and
 * the existing app shell describe layout the same way. `appearance` styles the
 * side rail itself and is ignored by the header shell.
 */
export interface PanelSidebarSettings {
    collapsible: boolean;
    defaultOpen: boolean;
    variant: 'sidebar' | 'header';
    appearance: PanelSidebarAppearance;
    /** CSS lengths: they become custom properties, never classes. */
    width: string;
    collapsedWidth: string;
    /** A build-time registry key replacing the shell's own sidebar. */
    component: string | null;
}

export type PanelSidebarAppearance = 'sidebar' | 'floating' | 'inset';

/** Matches Inertia's own `LinkPrefetchOption`. */
export type PanelPrefetchMode = 'hover' | 'mount' | 'click';

export interface PanelErrorNotification {
    title: string;
    body: string | null;
}

/** Mirrors `PandaPanel\Enums\RenderHook`. */
export type PanelRenderHookName =
    | 'body.start'
    | 'body.end'
    | 'sidebar.start'
    | 'sidebar.end'
    | 'header.start'
    | 'header.end'
    | 'page.start'
    | 'page.end';

export interface PanelRenderHookEntry {
    /** A key in the build-time hook registry, never a path or a class. */
    component: string;
    data: Record<string, unknown>;
    /** Empty means every page in the panel. */
    scopes: string[];
}

/**
 * One entry in the header's panel switcher.
 *
 * Only panels the user may enter are sent, and the URL is the server's, so
 * the switcher never offers a destination that would answer 403.
 */
export interface PanelSummary {
    id: string;
    name: string;
    brandName: string;
    path: string;
    icon: string | null;
    darkIcon: string | null;
    url: string;
    current: boolean;
}

/**
 * One tenant the signed-in user may enter.
 *
 * `url` is null when the panel never said how to build one — identification
 * is the application's (a subdomain, a path segment, one tenant per user), so
 * reversing it into a URL is too. The switcher hides itself rather than
 * offering entries that go nowhere.
 */
export interface PanelTenantSummary {
    key: string | number;
    name: string;
    url: string | null;
    current: boolean;
}

/**
 * Null — rather than an empty shape — for a panel with no tenancy, so the
 * check is `tenancy === null` and nothing tenant-shaped renders in an
 * application that has no tenants.
 */
export interface PanelTenancy {
    current: PanelTenantSummary | null;
    available: PanelTenantSummary[];
    /**
     * What the shell calls the tenant this request is in.
     *
     * Sent whether or not there is anywhere to switch to, because identity and
     * navigation are two questions. A user who belongs to one tenant has
     * nothing to switch between and still needs to see which company they are
     * working in.
     */
    label: string | null;
}

/**
 * One language the panel may be read in.
 *
 * `name` is written in that language rather than translated: somebody looking
 * for their own language is looking for the word they would use for it, and a
 * reader who cannot read the current locale cannot read "Indonesian" in it.
 */
export interface PanelLocale {
    code: string;
    name: string;
    current: boolean;
}

/**
 * The languages on offer, and where to post a choice.
 *
 * Null for a panel offering one language or none — the check is
 * `locales === null`, so no switcher renders for an application that has
 * nothing to switch between.
 */
export interface PanelLocales {
    current: string;
    url: string;
    available: PanelLocale[];
}

export interface PanelBroadcasting {
    enabled: boolean;
    /** The private channel this user's panel listens on, or null. */
    channel: string | null;
}

/**
 * The notification centre's endpoints and the unread count.
 *
 * The count comes down on every panel request rather than being polled, so
 * the bell is right after any navigation without a second round trip.
 */
export interface PanelNotificationSettings {
    enabled: boolean;
    /** Null when the bell is off, so there is nothing to ask. */
    indexUrl: string | null;
    readUrl: string | null;
    clearUrl: string | null;
    unread: number;
}

export type NotificationColorName = 'info' | 'success' | 'warning' | 'danger';

export interface PanelNotificationAction {
    name: string;
    label: string;
    /** A link the server produced, never an action name to resolve. */
    url: string | null;
    variant: 'default' | 'secondary' | 'outline' | 'ghost' | 'destructive';
    markAsRead: boolean;
    newTab: boolean;
}

export interface PanelNotificationItem {
    id: string;
    title: string;
    body: string | null;
    color: NotificationColorName;
    icon: string | null;
    actions: PanelNotificationAction[];
    read: boolean;
    createdAt: string | null;
}

export interface PanelSearchSettings {
    enabled: boolean;
    /** Null when searching is off, so there is nothing to ask. */
    url: string | null;
    debounce: number;
    keyBindings: string[];
}

export interface PanelSearchResult {
    title: string;
    url: string;
    details: Record<string, string>;
}

export interface PanelSearchGroup {
    resource: string;
    label: string;
    icon: string | null;
    results: PanelSearchResult[];
}

export interface PanelSharedProps {
    panel: PanelDefinition | null;
    navigation: NavigationGroup[];
    panels: PanelSummary[];
    broadcasting: PanelBroadcasting;
    search: PanelSearchSettings;
    notifications: PanelNotificationSettings;
    tenancy: PanelTenancy | null;
}
