import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import type { FlashToast } from '@/types/ui';
import { useTranslator } from '@/composables/useTranslator';
import { safeUrl } from '@/lib/utils';

export function initializeFlashToast(): void {
    // Read once: `useTranslator` hands back a closure over reactive props, so
    // a toast raised an hour later still speaks the locale of the page it was
    // raised on.
    const { t } = useTranslator();

    router.on('flash', (event) => {
        const flash = (event as CustomEvent).detail?.flash;
        const data = flash?.toast as FlashToast | undefined;

        if (!data) {
            return;
        }

        const url = safeUrl(data.url);

        toast[data.type](data.message, {
            // A link rather than a navigation: a toast that moved the page
            // would interrupt whatever the user did next.
            action: url
                ? {
                      label: data.urlLabel ?? t('ui.open'),
                      onClick: () => {
                          window.location.href = url;
                      },
                  }
                : undefined,
        });
    });
}
