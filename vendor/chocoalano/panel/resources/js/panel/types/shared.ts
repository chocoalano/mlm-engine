import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import type { NavigationGroup } from '@/panel/types/navigation';
import type {
    PanelBroadcasting,
    PanelDefinition,
    PanelLocales,
    PanelNotificationSettings,
    PanelSearchSettings,
    PanelSummary,
    PanelTenancy,
} from '@/panel/types/panel';

/**
 * Every prop `SharePanelData` puts on the page, as one type.
 *
 * Mirrors that middleware and nothing else. The host's own shared props —
 * `name`, `auth`, `sidebarOpen` — are deliberately absent: they belong to the
 * application, and a package that named them would be describing somebody
 * else's contract.
 */
export interface PanelSharedProps {
    /** Present only on panel routes: null elsewhere, never absent. */
    panel: PanelDefinition | null;
    navigation: NavigationGroup[];
    panels: PanelSummary[];
    broadcasting: PanelBroadcasting;
    search: PanelSearchSettings;
    notifications: PanelNotificationSettings;
    /**
     * Null for a panel that declared no tenancy, which is most of them — so
     * the check reads `tenancy === null` rather than testing an empty list.
     */
    tenancy: PanelTenancy | null;
    /** The locale the server resolved this request in. */
    locale: string;
    /** Null for a panel with one language or none, like `tenancy`. */
    locales: PanelLocales | null;
    /**
     * `lang/{locale}/frontend.php`, nested as written. Read through
     * `useTranslator()` rather than directly.
     */
    translations: Record<string, unknown>;
    /**
     * The protocol the backend speaks, and what to run when this frontend
     * does not speak it.
     *
     * Optional because it is newer than the components an application may have
     * published: a frontend new enough to read this is by definition the one
     * that can act on it, and one too old to read it is exactly the case a
     * backend-side warning has to cover instead.
     */
    contract?: PanelContract;
}

/**
 * See `PandaPanel\Support\FrontendContract`.
 */
export interface PanelContract {
    /** The version the backend expects this frontend to speak. */
    expected: number;
    /** The command that republishes the components. */
    remediation: string;
}

/**
 * The panel's own view of `usePage().props`.
 *
 * A cast, on purpose, and this is the only place in the panel that performs
 * one.
 *
 * The obvious alternative is a `declare module '@inertiajs/core'` module
 * augmentation, and that is what this package shipped. It does not survive
 * contact with an application: the augmentation has to land in a file the
 * host also owns, be picked up by the host's own `tsconfig` include, and
 * merge with whatever the starter kit already declares about the same
 * interface. When any one of those fails the type falls back to `{}` and
 * every read in `usePanel` and `useNavigation` becomes a compile error in
 * *the application's* build — fourteen of them, in a file the developer did
 * not write and cannot fix.
 *
 * Casting here moves that risk inside the package. The server is the
 * authority on this shape either way; what changes is that a host with no
 * augmentation, a conflicting one, or none in scope compiles exactly the
 * same. `tsconfig.host.json` is the check that keeps it that way.
 *
 * A `ComputedRef` rather than a plain read: `usePage()` is reactive and the
 * props change under a client-side navigation. Snapshotting them once would
 * type-check perfectly and leave the sidebar showing the panel the user left.
 */
export function panelSharedProps(): ComputedRef<PanelSharedProps> {
    const page = usePage();

    return computed(() => page.props as unknown as PanelSharedProps);
}
