import { router } from '@inertiajs/vue3';

export type UsePanelShellReturn = {
    reloadNavigation: () => void;
    reloadTopbar: () => void;
    reloadShell: () => void;
};

/**
 * Refetches part of the shell without reloading the page.
 *
 * The navigation, the notification count, and the panel definition are shared
 * props, so refreshing one is a partial reload of that prop and nothing else.
 * That is the whole mechanism: there is no second endpoint that answers "what
 * does the sidebar look like now", because such an endpoint would have to
 * re-resolve the panel, the user, and the current URL to say anything true —
 * which is exactly what a request already does.
 *
 * Reach for it after something that changes what the navigation says: a badge
 * that counts pending records, a resource that became visible, a tenant that
 * was switched.
 */
export function usePanelShell(): UsePanelShellReturn {
    return {
        reloadNavigation(): void {
            router.reload({ only: ['navigation'] });
        },

        reloadTopbar(): void {
            router.reload({ only: ['panel', 'notifications', 'panels'] });
        },

        reloadShell(): void {
            router.reload({
                only: ['navigation', 'panel', 'notifications', 'panels'],
            });
        },
    };
}
