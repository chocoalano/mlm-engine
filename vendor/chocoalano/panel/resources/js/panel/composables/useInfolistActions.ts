import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type { Ref } from 'vue';
import { safeUrl } from '@/lib/utils';
import { opensModal } from '@/panel/actions/intent';
import type { ActionDefinition, ActionEndpoints } from '@/panel/types/action';

export type UseInfolistActionsReturn = {
    pending: Ref<ActionDefinition | null>;
    processing: Ref<boolean>;
    formUrl: Ref<string | null>;
    formContext: Ref<Record<string, unknown>>;
    run: (action: ActionDefinition) => void;
    confirm: () => void;
    cancel: () => void;
};

/**
 * Runs an action a record's infolist declared.
 *
 * The same shape `useActions` has, deliberately kept separate rather than a
 * flag on it: these post to a different endpoint because they are resolved
 * against a different whitelist. A view page's actions come from
 * `Resource::infolist()`, and folding the two together would mean an action
 * shown on one page could be run from the other.
 *
 * The request carries the action name, the resource slug, and the record key.
 * It never carries anything executable: the server looks the handler up in
 * the schema that declared it and authorizes again before running it.
 */
export function useInfolistActions(
    resourceSlug: () => string,
    endpoints: () => ActionEndpoints,
    recordKey: () => string | number,
): UseInfolistActionsReturn {
    const pending = ref<ActionDefinition | null>(null);
    const processing = ref(false);

    const formUrl = computed<string | null>(() => {
        const action = pending.value;

        if (action === null || action.type !== 'form') {
            return null;
        }

        if (action.formUrl !== null) {
            return action.formUrl;
        }

        if (!action.hasForm) {
            return null;
        }

        const url = new URL(endpoints().form, window.location.origin);

        url.searchParams.set('resource', resourceSlug());
        url.searchParams.set('action', action.name);
        url.searchParams.set('scope', 'infolist');
        url.searchParams.set('record', String(recordKey()));

        return url.pathname + url.search;
    });

    const formContext = computed<Record<string, unknown>>(() => ({
        resource: resourceSlug(),
        action: pending.value?.name ?? '',
        scope: 'infolist',
        record: recordKey(),
    }));

    function dispatch(action: ActionDefinition): void {
        processing.value = true;

        router.post(
            endpoints().infolist,
            {
                resource: resourceSlug(),
                action: action.name,
                record: recordKey(),
            },
            {
                preserveScroll: true,
                onFinish: () => {
                    processing.value = false;
                    pending.value = null;
                },
            },
        );
    }

    return {
        pending,
        processing,
        formUrl,
        formContext,

        run(action): void {
            const url = safeUrl(action.url);

            if (action.type === 'link' && url) {
                router.visit(url);

                return;
            }

            // Held until the user has said something more — see
            // `opensModal()`.
            if (opensModal(action)) {
                pending.value = action;

                return;
            }

            dispatch(action);
        },

        confirm(): void {
            if (pending.value) {
                dispatch(pending.value);
            }
        },

        cancel(): void {
            pending.value = null;
        },
    };
}
