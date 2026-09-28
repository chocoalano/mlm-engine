import { echo } from '@laravel/echo-vue';
import { onBeforeUnmount, onMounted } from 'vue';
import { toast } from 'vue-sonner';
import { useTranslator } from '@/composables/useTranslator';
import { safeUrl } from '@/lib/utils';
import { usePanel } from '@/panel/composables/usePanel';
import type { FlashToast } from '@/types/ui';

/** Matches `PanelNotification::broadcastAs()`; the dot marks a custom name. */
const EVENT = '.panel.notification';

const TYPES: readonly FlashToast['type'][] = [
    'success',
    'info',
    'warning',
    'error',
];

interface PanelToast extends FlashToast {
    /** Something to open, for a job whose result is a file. */
    url: string | null;
    urlLabel: string | null;
}

/**
 * A payload arriving over a websocket has crossed the same boundary an HTTP
 * response does, and is validated the same way rather than trusted.
 */
function toFlashToast(payload: unknown): PanelToast | null {
    if (typeof payload !== 'object' || payload === null) {
        return null;
    }

    const { type, message, url, urlLabel } = payload as Record<string, unknown>;

    if (typeof message !== 'string' || typeof type !== 'string') {
        return null;
    }

    return TYPES.includes(type as FlashToast['type'])
        ? {
              type: type as FlashToast['type'],
              message,
              url: typeof url === 'string' && url !== '' ? url : null,
              urlLabel: typeof urlLabel === 'string' ? urlLabel : null,
          }
        : null;
}

/**
 * Reaching Echo without letting it take the panel down with it.
 *
 * `echo()` throws when `configureEcho()` was never called, and this runs in
 * `onMounted` on the panel shell — so the throw aborted the layout's mount
 * and Inertia then produced a dozen `Slot "default" invoked outside of the
 * render function` warnings swapping a layout that had never finished. The
 * server now withholds the channel unless the application has a broadcaster,
 * which removes the common case; this covers the rest — a broadcaster
 * configured in PHP that the frontend never wired up.
 *
 * One warning, in development only, then the panel carries on without
 * realtime notifications. The feature dies; the screen does not.
 */
let warned = false;

function withEcho<T>(use: (client: ReturnType<typeof echo>) => T): T | null {
    try {
        return use(echo());
    } catch (error) {
        if (!warned && import.meta.env.DEV) {
            warned = true;

            console.warn(
                '[panel] Realtime notifications are off: this panel has broadcasting ' +
                    'enabled and a broadcaster configured, but Echo was never set up in ' +
                    'the browser. Call configureEcho({ broadcaster: … }) in resources/js/app.ts, ' +
                    'or turn it off with ->broadcasting(false) on the panel.',
                error,
            );
        }

        return null;
    }
}

/**
 * Subscribes the panel to its own notifications.
 *
 * Nothing connects unless the server sent a channel, which it only does for a
 * signed-in user, on a panel with broadcasting on, in an application that has
 * a broadcaster configured. That is what makes `broadcasting(false)` — and an
 * application with no broadcaster at all — cost nothing rather than merely
 * hiding a connection that was attempted anyway.
 *
 * Notifications land as the same toast a flash message does, so a job that
 * finishes long after its request reaches the user the same way.
 */
export function usePanelBroadcasting(): void {
    const { broadcasting } = usePanel();
    const { t } = useTranslator();

    let subscribed: string | null = null;

    onMounted(() => {
        const channel = broadcasting.value.channel;

        if (channel === null) {
            return;
        }

        const listening = withEcho((client) =>
            client.private(channel).listen(EVENT, (payload: unknown) => {
                const notification = toFlashToast(payload);

                if (notification === null) {
                    return;
                }

                const persistent =
                    typeof payload === 'object' &&
                    payload !== null &&
                    (payload as { persistent?: unknown }).persistent === true;

                // A persisted notification is also a row in the bell. The
                // event is raised before the toast so the count is right even
                // if the toast is dismissed instantly.
                if (persistent) {
                    window.dispatchEvent(new CustomEvent('panel:notification'));
                }

                const url = safeUrl(notification.url);

                toast[notification.type](notification.message, {
                    // A link rather than a navigation: the toast may arrive
                    // while the user is in the middle of something else, and
                    // moving them somewhere they did not ask to go would be
                    // worse than the file waiting.
                    action:
                        url === null
                            ? undefined
                            : {
                                  label: notification.urlLabel ?? t('ui.open'),
                                  onClick: () => {
                                      window.location.href = url;
                                  },
                              },
                });
            }),
        );

        // Only remembered when the subscription actually happened, so the
        // teardown below does not call into an Echo that was never there.
        subscribed = listening === null ? null : channel;
    });

    onBeforeUnmount(() => {
        if (subscribed !== null) {
            withEcho((client) => client.leave(subscribed as string));
            subscribed = null;
        }
    });
}
