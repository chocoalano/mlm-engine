import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';
import { toast } from 'vue-sonner';
import { usePanel } from '@/panel/composables/usePanel';
import type { PanelErrorNotification } from '@/panel/types/panel';

/**
 * Turns a failed request into a toast instead of Inertia's error overlay.
 *
 * The panel owns the copy, keyed by status. Three outcomes, matching the
 * three things a panel can want:
 *
 * - an entry: show it, and suppress the overlay
 * - an entry explicitly set to null: suppress both, silently
 * - no entry at all: leave Inertia alone, because a status the panel has
 *   nothing to say about is better shown raw than swallowed
 *
 * Returning false from the handler is what cancels Inertia's own handling.
 */
export function useErrorNotifications(): void {
    const { panel } = usePanel();

    function notificationFor(
        status: number,
    ): PanelErrorNotification | null | undefined {
        return panel.value?.errorNotifications?.[String(status)];
    }

    let stop: (() => void) | null = null;

    onMounted(() => {
        stop = router.on('httpException', (event) => {
            const status = event.detail.response.status;
            const notification = notificationFor(status);

            if (notification === undefined) {
                return;
            }

            if (notification !== null) {
                toast.error(notification.title, {
                    description: notification.body ?? undefined,
                });
            }

            return false;
        });
    });

    onBeforeUnmount(() => stop?.());
}
