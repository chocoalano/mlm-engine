/**
 * @vitest-environment happy-dom
 *
 * Opted in per file rather than globally: this composable resolves its form
 * URL against `window.location.origin`, and the rest of the suite is pure
 * functions that should not pay for a DOM they never touch.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ActionDefinition } from '@/panel/types/action';
import type { RelationEndpoints } from '@/panel/types/relation';

/**
 * The branch a relation action takes.
 *
 * This is the file the P0 defect lived in, and the defect was not a wrong
 * value — it was a request that was never made and one that was made when it
 * should not have been. A row action carrying its own schema fell past the
 * "hold this until the user says more" branch and ran immediately: no dialog,
 * no values, and a handler invoked with an empty array. A header action
 * carrying one did nothing at all.
 *
 * Neither is visible to a server test, because the failure is on the wire
 * before the server is reached. Neither is visible to `vue-tsc`, because both
 * shapes type-check. So the router is the boundary that gets mocked, and what
 * is asserted is what crossed it — or that nothing did.
 */
const post = vi.fn();
const visit = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    router: {
        post: (...args: unknown[]) => post(...args),
        visit: (...args: unknown[]) => visit(...args),
    },
}));

const { useRelationActions } =
    await import('@/panel/composables/useRelationActions');

const ENDPOINTS: RelationEndpoints = {
    form: '/panel/relations/form?resource=projects',
    save: '/panel/relations/form?resource=projects',
    action: '/panel/relations/action',
    bulk: '/panel/relations/bulk',
    actionForm:
        '/panel/relations/action-form?resource=projects&record=1&relation=tasks',
};

const CONTEXT = { resource: 'projects', record: 1, relation: 'tasks' };

/** A serialized action, in the shape `Action::toArray()` actually sends. */
function action(overrides: Partial<ActionDefinition> = {}): ActionDefinition {
    return {
        name: 'doThing',
        label: 'Do thing',
        icon: null,
        variant: 'default',
        type: 'callback',
        url: null,
        formUrl: null,
        hasForm: false,
        modal: null,
        modalActions: [],
        confirmation: null,
        ...overrides,
    };
}

function subject() {
    return useRelationActions(
        () => CONTEXT,
        () => ENDPOINTS,
    );
}

/** The body of the single request the router was asked to make. */
function postedBody(): Record<string, unknown> {
    expect(post).toHaveBeenCalledTimes(1);

    return post.mock.calls[0][1] as Record<string, unknown>;
}

function postedUrl(): string {
    return post.mock.calls[0][0] as string;
}

beforeEach(() => {
    post.mockClear();
    visit.mockClear();
});

/*
 * T1–T3 — the scope a request is made under
 *
 * Asserted through the request rather than by reaching for the private
 * helper: the scope only matters because it decides which whitelist the
 * server resolves the name in, and that is what travels.
 */

describe('scope classification', () => {
    it('runs a row action under the record scope, carrying the row', () => {
        const { runRecord } = subject();

        runRecord(action(), 42);

        expect(postedBody()).toMatchObject({ scope: 'record', related: 42 });
    });

    it('runs a header action under the table scope, carrying no row', () => {
        const { runHeader } = subject();

        runHeader(action());

        const body = postedBody();

        // Not a falsy row — no row key at all. A header action has none, and
        // inventing one would hand the server a record to resolve.
        expect(body).toMatchObject({ scope: 'table' });
        expect(body).not.toHaveProperty('related');
    });

    it('runs a bulk action under the bulk scope, carrying the selection', () => {
        const { runBulk } = subject();

        runBulk(action(), [1, 2, 3]);

        const body = postedBody();

        expect(body).toMatchObject({ records: [1, 2, 3] });
        expect(body).not.toHaveProperty('related');
        // Bulk has its own endpoint, so the scope is already implied by it.
        expect(postedUrl()).toBe(ENDPOINTS.bulk);
    });

    it('sends a row action and a header action to the same endpoint', () => {
        const { runRecord } = subject();

        runRecord(action(), 7);

        expect(postedUrl()).toBe(ENDPOINTS.action);
    });
});

/*
 * T4 / T6 / T8 — no form means run now
 */

describe('actions without a form', () => {
    it('runs a row action immediately', () => {
        const { runRecord, pending } = subject();

        runRecord(action(), 42);

        expect(post).toHaveBeenCalledTimes(1);
        expect(pending.value).toBeNull();
    });

    it('runs a header action immediately', () => {
        const { runHeader, pending } = subject();

        runHeader(action());

        expect(post).toHaveBeenCalledTimes(1);
        expect(pending.value).toBeNull();
    });

    it('runs a bulk action immediately', () => {
        const { runBulk, pending } = subject();

        runBulk(action(), [4, 5]);

        expect(post).toHaveBeenCalledTimes(1);
        expect(pending.value).toBeNull();
    });

    it('holds an action that asks for confirmation', () => {
        const { runRecord, pending } = subject();

        runRecord(
            action({
                confirmation: {
                    heading: 'Sure?',
                    description: '',
                    button: 'Yes',
                },
            }),
            42,
        );

        expect(post).not.toHaveBeenCalled();
        expect(pending.value).not.toBeNull();
    });

    it('navigates for a link action instead of posting', () => {
        const { runRecord } = subject();

        runRecord(action({ type: 'link', url: '/somewhere' }), 42);

        expect(post).not.toHaveBeenCalled();
        expect(visit).toHaveBeenCalledWith('/somewhere');
    });
});

/*
 * T5 / T7 / T9 — a form means open a form, and run nothing
 *
 * The regression assertion in each is `post` not having been called. That is
 * the defect stated as a test: the old code ran the handler here.
 */

describe('actions with a form', () => {
    it('holds a row action and builds its form URL', () => {
        const { runRecord, pending, formUrl } = subject();

        runRecord(action({ type: 'form', hasForm: true }), 42);

        expect(post).not.toHaveBeenCalled();
        expect(pending.value).not.toBeNull();

        const url = new URL(formUrl.value as string, 'http://localhost');

        expect(url.pathname).toBe('/panel/relations/action-form');
        expect(url.searchParams.get('action')).toBe('doThing');
        expect(url.searchParams.get('scope')).toBe('record');
        expect(url.searchParams.get('related')).toBe('42');
        // The server's own context survives; the client only appends.
        expect(url.searchParams.get('relation')).toBe('tasks');
        expect(url.searchParams.get('record')).toBe('1');
    });

    it('holds a header action and builds a form URL with no row', () => {
        const { runHeader, pending, formUrl } = subject();

        runHeader(action({ type: 'form', hasForm: true }));

        // The branch that was entirely dead: a header action with a schema
        // used to reach nothing at all.
        expect(post).not.toHaveBeenCalled();
        expect(pending.value).not.toBeNull();

        const url = new URL(formUrl.value as string, 'http://localhost');

        expect(url.searchParams.get('scope')).toBe('table');
        expect(url.searchParams.has('related')).toBe(false);
    });

    it('holds a bulk action and carries the selection to the form', () => {
        const { runBulk, formUrl, formContext } = subject();

        runBulk(action({ type: 'form', hasForm: true }), [8, 9]);

        expect(post).not.toHaveBeenCalled();

        const url = new URL(formUrl.value as string, 'http://localhost');

        expect(url.searchParams.get('scope')).toBe('bulk');
        expect(url.searchParams.has('related')).toBe(false);
        // The one thing a URL cannot carry, so it rides with the form body.
        expect(formContext.value).toEqual({ records: [8, 9] });
    });

    it('uses the URL the server built for a relation operation as given', () => {
        const { runRecord, formUrl } = subject();

        // Create, edit, attach and associate name an owner and an operation
        // this composable knows nothing about, so it must not assemble one.
        runRecord(
            action({
                type: 'form',
                hasForm: false,
                formUrl: '/panel/relations/form?operation=edit&related=42',
            }),
            42,
        );

        expect(post).not.toHaveBeenCalled();
        expect(formUrl.value).toBe(
            '/panel/relations/form?operation=edit&related=42',
        );
    });

    it('sends an empty form context for a row action', () => {
        const { runRecord, formContext } = subject();

        runRecord(action({ type: 'form', hasForm: true }), 42);

        // Everything a row action needs is already in the submit URL's query
        // string, where a field named `action` cannot overwrite it.
        expect(formContext.value).toEqual({});
    });
});

/*
 * T17 — fail closed
 */

describe('a form action that cannot be described', () => {
    it('does not fall back to running the action', () => {
        const { runRecord, pending, formUrl } = subject();

        // `type: form` with neither a server-built URL nor a schema the panel
        // endpoint could describe. The old failure mode was to treat "no form
        // URL" as "no form" and execute; the action is held instead, and the
        // dialog reports that it could not load.
        runRecord(action({ type: 'form', hasForm: false }), 42);

        expect(post).not.toHaveBeenCalled();
        expect(pending.value).not.toBeNull();
        expect(formUrl.value).toBeNull();
    });

    it('runs nothing when confirm is reached with no pending action', () => {
        const { confirm } = subject();

        confirm();

        expect(post).not.toHaveBeenCalled();
    });
});

/*
 * Lifecycle
 */

describe('pending lifecycle', () => {
    it('clears the held action when cancelled, without running it', () => {
        const { runRecord, cancel, pending, formUrl } = subject();

        runRecord(action({ type: 'form', hasForm: true }), 42);
        cancel();

        expect(post).not.toHaveBeenCalled();
        expect(pending.value).toBeNull();
        // The computed follows the pending action rather than latching.
        expect(formUrl.value).toBeNull();
    });

    it('runs a confirmed action once the dialog is accepted', () => {
        const { runRecord, confirm } = subject();

        runRecord(
            action({
                confirmation: {
                    heading: 'Sure?',
                    description: '',
                    button: 'Yes',
                },
            }),
            42,
        );

        expect(post).not.toHaveBeenCalled();

        confirm();

        expect(postedBody()).toMatchObject({ scope: 'record', related: 42 });
    });

    it('recomputes the form URL when a second action is opened', () => {
        const { runRecord, runHeader, formUrl } = subject();

        runRecord(action({ type: 'form', hasForm: true }), 42);

        expect(
            new URL(
                formUrl.value as string,
                'http://localhost',
            ).searchParams.get('scope'),
        ).toBe('record');

        runHeader(action({ name: 'other', type: 'form', hasForm: true }));

        const url = new URL(formUrl.value as string, 'http://localhost');

        expect(url.searchParams.get('scope')).toBe('table');
        expect(url.searchParams.get('action')).toBe('other');
    });
});
