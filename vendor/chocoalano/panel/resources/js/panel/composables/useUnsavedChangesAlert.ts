import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';
import type { Ref } from 'vue';
import { useTranslator } from '@/composables/useTranslator';
import { usePanel } from '@/panel/composables/usePanel';

/**
 * Warns before leaving a form with unsaved edits.
 *
 * Two escapes have to be covered and they are not the same: an Inertia visit
 * stays in the document, so it is cancelled from the `before` event, while a
 * reload or a closed tab leaves it entirely and only `beforeunload` sees
 * that. The native prompt is the only option for the second, so the first
 * uses `confirm` too rather than showing two different dialogs for one
 * decision.
 *
 * The panel decides whether this runs at all; a form outside a panel is left
 * alone.
 */
export function useUnsavedChangesAlert(isDirty: Ref<boolean>): void {
    const { panel } = usePanel();
    const { t } = useTranslator();

    const enabled = (): boolean =>
        panel.value?.unsavedChangesAlerts === true && isDirty.value;

    function onBeforeUnload(event: BeforeUnloadEvent): void {
        if (!enabled()) {
            return;
        }

        // Assigning returnValue is what makes the browser prompt; the text
        // itself is ignored by every current browser.
        event.preventDefault();
        event.returnValue = t('shell.unsaved_changes');
    }

    let stopInertia: (() => void) | null = null;

    onMounted(() => {
        window.addEventListener('beforeunload', onBeforeUnload);

        // Returning false cancels the visit.
        stopInertia = router.on('before', () => {
            if (!enabled()) {
                return;
            }

            return window.confirm(t('shell.unsaved_changes'));
        });
    });

    onBeforeUnmount(() => {
        window.removeEventListener('beforeunload', onBeforeUnload);
        stopInertia?.();
    });
}
