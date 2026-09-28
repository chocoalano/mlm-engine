import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type { Ref } from 'vue';
import { safeUrl } from '@/lib/utils';
import { opensModal } from '@/panel/actions/intent';
import type { ActionDefinition } from '@/panel/types/action';
import type { RelationEndpoints } from '@/panel/types/relation';

export type RelationContext = {
    resource: string;
    record: string | number;
    relation: string;
};

export type PendingRelationAction = {
    action: ActionDefinition;
    related: string | number | null;
    records: Array<string | number>;
    /** Acts on the relation rather than on a row, so it carries no record. */
    header?: boolean;
};

export type UseRelationActionsReturn = {
    pending: Ref<PendingRelationAction | null>;
    processing: Ref<boolean>;
    /**
     * Where the pending action's form is fetched from, or null when it has
     * none. Built from the endpoint the server sent plus the names of what
     * this action is about — the client never assembles a panel URL.
     */
    formUrl: Ref<string | null>;
    /**
     * Posted alongside that form. Empty for a row action, whose whole context
     * is already in the submit URL's query string; a bulk action adds the
     * selection, which is the one thing the URL cannot carry.
     */
    formContext: Ref<Record<string, unknown>>;
    runRecord: (action: ActionDefinition, related: string | number) => void;
    runBulk: (
        action: ActionDefinition,
        records: Array<string | number>,
    ) => void;
    /** An action about the relation itself rather than about one of its rows. */
    runHeader: (action: ActionDefinition) => void;
    confirm: () => void;
    cancel: () => void;
};

/**
 * Runs an action a relation manager's table declared.
 *
 * The request names the resource, the owner record, the relation, and the
 * action. Nothing executable, and nothing that widens what may be reached:
 * the server looks the handler up in the schema of the relation it was given
 * and loads the related record through that relation, so a key belonging to
 * another owner resolves to nothing.
 *
 * An action requiring confirmation is held until the dialog is accepted, so
 * the request is only ever made once the user agreed.
 */
export function useRelationActions(
    context: () => RelationContext,
    endpoints: () => RelationEndpoints,
): UseRelationActionsReturn {
    const pending = ref<PendingRelationAction | null>(null);
    const processing = ref(false);

    /**
     * Null for an action with no form, which is most of them.
     *
     * An action that carries its own schema is described by the relation's
     * action-form endpoint — not the panel's, which resolves actions out of
     * the *resource's* table and cannot see a relation manager's. That
     * mismatch is why every relation action with a form used to 404.
     *
     * An action that instead carries a `formUrl` is one of the relation's own
     * operations (create, edit, attach, associate). Those name an owner and an
     * operation this composable knows nothing about, so the server sends the
     * whole URL and it is used as given.
     */
    /**
     * Which whitelist the server should look this action up in.
     *
     * The same three the relation endpoints accept, and the only thing that
     * decides it is what the action was run *as* — never whether a related
     * key happens to be present.
     */
    function scopeOf(
        request: PendingRelationAction,
    ): 'record' | 'table' | 'bulk' {
        if (request.header) {
            return 'table';
        }

        return request.records.length > 0 ? 'bulk' : 'record';
    }

    const formUrl = computed<string | null>(() => {
        const request = pending.value;

        if (request === null || request.action.type !== 'form') {
            return null;
        }

        if (request.action.formUrl !== null) {
            return request.action.formUrl;
        }

        if (!request.action.hasForm) {
            return null;
        }

        const url = new URL(endpoints().actionForm, window.location.origin);

        url.searchParams.set('action', request.action.name);
        url.searchParams.set('scope', scopeOf(request));

        // Only a row action is about one. A header action has no row and a
        // bulk action has a selection, and neither may hand the server a
        // related key it would then resolve.
        if (!request.header && request.related !== null) {
            url.searchParams.set('related', String(request.related));
        }

        return url.pathname + url.search;
    });

    const formContext = computed<Record<string, unknown>>(() =>
        pending.value !== null && pending.value.records.length > 0
            ? { records: pending.value.records }
            : {},
    );

    function dispatch(request: PendingRelationAction): void {
        const scope = scopeOf(request);
        const { resource, record, relation } = context();

        processing.value = true;

        router.post(
            scope === 'bulk' ? endpoints().bulk : endpoints().action,
            {
                resource,
                record,
                relation,
                action: request.action.name,
                // Says which whitelist to resolve the name in. A header action
                // and a row action can share a name and be different actions.
                ...(scope === 'bulk' ? {} : { scope }),
                ...(scope === 'bulk'
                    ? { records: request.records }
                    : scope === 'table'
                      ? {}
                      : { related: request.related }),
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

    function start(request: PendingRelationAction): void {
        const url = safeUrl(request.action.url);

        if (request.action.type === 'link' && url !== null) {
            router.visit(url);

            return;
        }

        // Held until the user has said something more — the same three
        // declarations every other entry point honours. See `opensModal()`.
        if (opensModal(request.action)) {
            pending.value = request;

            return;
        }

        dispatch(request);
    }

    return {
        pending,
        processing,
        formUrl,
        formContext,

        runRecord(action, related): void {
            start({ action, related, records: [] });
        },

        runBulk(action, records): void {
            start({ action, related: null, records });
        },

        runHeader(action): void {
            start({ action, related: null, records: [], header: true });
        },

        /**
         * Only ever reached by an action without a form: one that has a form
         * submits through the form, and the dialog renders no confirm button
         * beside it.
         */
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
