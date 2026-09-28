/**
 * @vitest-environment happy-dom
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ActionDefinition, ActionEndpoints } from '@/panel/types/action';

/**
 * The resource side of the same contract.
 *
 * Relation actions and resource actions share `ActionModal` and
 * `FormRenderer`, so the P0 work on the relation half could plausibly have
 * moved something under this one. These are the branches that would show it:
 * which endpoint a scope posts to, and that a form action is still held
 * rather than run.
 */
const post = vi.fn();
const visit = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    router: {
        post: (...args: unknown[]) => post(...args),
        visit: (...args: unknown[]) => visit(...args),
    },
}));

const { useActions } = await import('@/panel/composables/useActions');

const ENDPOINTS: ActionEndpoints = {
    record: '/panel/actions/record',
    bulk: '/panel/actions/bulk',
    reorder: '/panel/actions/reorder',
    cell: '/panel/actions/cell',
    table: '/panel/actions/table',
    form: '/panel/actions/form',
    infolist: '/panel/actions/infolist',
};

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
    return useActions(
        () => 'projects',
        () => ENDPOINTS,
    );
}

beforeEach(() => {
    post.mockClear();
    visit.mockClear();
});

describe('resource actions', () => {
    it('posts a record action to the record endpoint', () => {
        const { runRecord } = subject();

        runRecord(action(), 42);

        expect(post.mock.calls[0][0]).toBe(ENDPOINTS.record);
        expect(post.mock.calls[0][1]).toMatchObject({
            resource: 'projects',
            action: 'doThing',
            record: 42,
        });
    });

    it('posts a table action to the table endpoint, with no record', () => {
        const { runTable } = subject();

        runTable(action());

        expect(post.mock.calls[0][0]).toBe(ENDPOINTS.table);
        expect(post.mock.calls[0][1]).not.toHaveProperty('record');
    });

    it('posts a bulk action with its selection', () => {
        const { runBulk } = subject();

        runBulk(action(), [1, 2]);

        expect(post.mock.calls[0][0]).toBe(ENDPOINTS.bulk);
        expect(post.mock.calls[0][1]).toMatchObject({ records: [1, 2] });
    });

    it('holds a form action rather than running it', () => {
        const { runRecord, pending, formUrl } = subject();

        runRecord(action({ type: 'form', hasForm: true }), 42);

        expect(post).not.toHaveBeenCalled();
        expect(pending.value).not.toBeNull();

        const url = new URL(formUrl.value as string, 'http://localhost');

        expect(url.pathname).toBe('/panel/actions/form');
        expect(url.searchParams.get('resource')).toBe('projects');
        expect(url.searchParams.get('scope')).toBe('record');
        expect(url.searchParams.get('record')).toBe('42');
    });

    it('scopes a bulk form action as bulk', () => {
        const { runBulk, formUrl } = subject();

        runBulk(action({ type: 'form', hasForm: true }), [1, 2]);

        const url = new URL(formUrl.value as string, 'http://localhost');

        expect(url.searchParams.get('scope')).toBe('bulk');
    });

    it('does not run a form action it cannot describe', () => {
        const { runRecord, formUrl } = subject();

        runRecord(action({ type: 'form', hasForm: false }), 42);

        // Same fail-closed rule the relation side follows.
        expect(post).not.toHaveBeenCalled();
        expect(formUrl.value).toBeNull();
    });

    it('writes a cell through its own endpoint', () => {
        const { editCell } = subject();

        editCell(7, 'status', 'done');

        expect(post.mock.calls[0][0]).toBe(ENDPOINTS.cell);
        expect(post.mock.calls[0][1]).toMatchObject({
            record: 7,
            column: 'status',
            value: 'done',
        });
    });
});
