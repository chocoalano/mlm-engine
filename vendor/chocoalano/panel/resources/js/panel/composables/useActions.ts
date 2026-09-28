import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type { Ref } from 'vue';
import { safeUrl } from '@/lib/utils';
import { opensModal } from '@/panel/actions/intent';
import type { ActionDefinition, ActionEndpoints } from '@/panel/types/action';

/**
 * What an editable cell can send. Narrower than `unknown` because it has to
 * survive the wire, and every editable column produces one of these.
 */
export type CellEditValue = string | number | boolean | null;

export type PendingAction = {
    action: ActionDefinition;
    record: string | number | null;
    records: Array<string | number>;
    /** Acts on the table rather than a row, so it carries no record at all. */
    table?: boolean;
};

export type UseActionsReturn = {
    /**
     * Where the pending action's form is fetched from, or null when it has
     * none. Built from the endpoint the server sent plus the names of what
     * this action is about — the client never assembles a panel URL.
     */
    formUrl: Ref<string | null>;
    /** Posted alongside that form, so the submit knows what it is running. */
    formContext: Ref<Record<string, unknown>>;
    editCell: (
        record: string | number,
        column: string,
        value: CellEditValue,
    ) => void;
    pending: Ref<PendingAction | null>;
    processing: Ref<boolean>;
    runRecord: (action: ActionDefinition, record: string | number) => void;
    runBulk: (
        action: ActionDefinition,
        records: Array<string | number>,
    ) => void;
    runTable: (action: ActionDefinition) => void;
    reorder: (records: Array<string | number>) => void;
    confirm: () => void;
    cancel: () => void;
};

/**
 * Runs a backend-declared action.
 *
 * The request carries the action name, the resource slug, and record keys.
 * It never carries anything executable: the server looks the handler up in
 * the schema that declared it and authorizes again before running it.
 *
 * An action that requires confirmation is held here until the dialog is
 * accepted, so the request is only ever made once the user agreed.
 */
export function useActions(
    resourceSlug: () => string,
    endpoints: () => ActionEndpoints,
    /**
     * The parent a nested resource is scoped to. The action endpoints are one
     * per panel and carry no parent segment, so without this an action on a
     * nested resource would run unscoped.
     */
    parentKey: () => string | number | null = () => null,
): UseActionsReturn {
    const pending = ref<PendingAction | null>(null);
    const processing = ref(false);

    /**
     * The list as it is currently filtered, sent with a table or bulk action.
     *
     * An action posts to an endpoint of its own, so the filters and the search
     * term that were on screen are not in the request unless they are sent —
     * and an export that ignored them would hand back a different set of
     * records from the one being looked at. The server puts every value back
     * through the table's own schema, which is the whitelist.
     */
    function tableState(): Record<string, string> {
        const state: Record<string, string> = {};

        new URL(window.location.href).searchParams.forEach((value, key) => {
            state[key] = value;
        });

        return state;
    }

    function scopeOf(request: PendingAction): 'record' | 'table' | 'bulk' {
        if (request.table) {
            return 'table';
        }

        return request.records.length > 0 ? 'bulk' : 'record';
    }

    /**
     * Null for an action with no form, which is most of them.
     *
     * A relation action carries its own URL because that one names an owner
     * and an operation this endpoint knows nothing about; everything else is
     * described by the panel's action-form endpoint.
     */
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

        const url = new URL(endpoints().form, window.location.origin);

        url.searchParams.set('resource', resourceSlug());
        url.searchParams.set('action', request.action.name);
        url.searchParams.set('scope', scopeOf(request));

        if (request.record !== null) {
            url.searchParams.set('record', String(request.record));
        }

        if (parentKey() !== null) {
            url.searchParams.set('parent', String(parentKey()));
        }

        return url.pathname + url.search;
    });

    const formContext = computed<Record<string, unknown>>(() => {
        const request = pending.value;

        if (request === null) {
            return {};
        }

        return {
            resource: resourceSlug(),
            action: request.action.name,
            scope: scopeOf(request),
            tableState: tableState(),
            ...(request.record === null ? {} : { record: request.record }),
            ...(request.records.length > 0 ? { records: request.records } : {}),
            ...(parentKey() === null ? {} : { parent: parentKey() }),
        };
    });

    function dispatch(request: PendingAction): void {
        const isBulk = request.records.length > 0;

        processing.value = true;

        const endpoint = request.table
            ? endpoints().table
            : isBulk
              ? endpoints().bulk
              : endpoints().record;

        router.post(
            endpoint,
            {
                resource: resourceSlug(),
                action: request.action.name,
                ...(parentKey() === null ? {} : { parent: parentKey() }),
                ...(request.table
                    ? {}
                    : isBulk
                      ? { records: request.records }
                      : { record: request.record }),
                // Only where it means something: a record action is about one
                // record, and what the list was filtered to has no bearing on
                // it.
                ...(request.table || isBulk
                    ? { tableState: tableState() }
                    : {}),
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

    function start(request: PendingAction): void {
        const url = safeUrl(request.action.url);

        if (request.action.type === 'link' && url) {
            router.visit(url);

            return;
        }

        // Held until the user has said something more — see `opensModal()`
        // for the three declarations that mean that, and for the one that was
        // being ignored.
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

        runRecord(action, record): void {
            start({ action, record, records: [] });
        },

        runBulk(action, records): void {
            start({ action, record: null, records });
        },

        runTable(action): void {
            start({ action, record: null, records: [], table: true });
        },

        /**
         * Reordering is not an action: there is nothing to confirm and no
         * handler to look up, only a new order to record. It posts to its
         * own endpoint and never enters the confirmation flow.
         */
        reorder(records): void {
            processing.value = true;

            router.post(
                endpoints().reorder,
                {
                    resource: resourceSlug(),
                    records,
                    ...(parentKey() === null ? {} : { parent: parentKey() }),
                },
                {
                    preserveScroll: true,
                    onFinish: () => {
                        processing.value = false;
                    },
                },
            );
        },

        /**
         * Writes one cell.
         *
         * Nothing is applied locally first: the server validates the value,
         * authorizes the record, and re-checks whether the cell is writable
         * for it, then answers with the row as it now is. An optimistic
         * update would have to guess all three.
         */
        editCell(record, column, value): void {
            processing.value = true;

            router.post(
                endpoints().cell,
                {
                    resource: resourceSlug(),
                    record,
                    column,
                    value,
                    ...(parentKey() === null ? {} : { parent: parentKey() }),
                },
                {
                    preserveScroll: true,
                    onFinish: () => {
                        processing.value = false;
                    },
                },
            );
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
