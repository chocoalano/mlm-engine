/**
 * @vitest-environment happy-dom
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { opensModal } from '@/panel/actions/intent';
import type { ActionDefinition, ModalDefinition } from '@/panel/types/action';

/**
 * A non-form action that declared modal copy must wait to be confirmed.
 *
 * The API accepted `modalHeading()` and `modalDescription()`, serialized both,
 * and then every composable ignored them: the action ran its handler on the
 * first click and neither sentence was ever shown. An accepted declaration
 * that cannot work is worse than one that does not exist — the developer had
 * every reason to believe the guard was there, and destructive actions were
 * written on that belief.
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
const { useRelationActions } =
    await import('@/panel/composables/useRelationActions');
const { useInfolistActions } =
    await import('@/panel/composables/useInfolistActions');

const MODAL: ModalDefinition = {
    width: 'md',
    slideOver: false,
    stickyHeader: false,
    stickyFooter: false,
    closeByClickingAway: true,
    closeByEscaping: true,
    autofocus: true,
    heading: 'Approve machine?',
    description: 'This grants password-less attendance access immediately.',
    submitLabel: 'Approve',
    cancelLabel: 'Keep pending',
    cancel: true,
    componentName: null,
    config: {},
};

function action(overrides: Partial<ActionDefinition> = {}): ActionDefinition {
    return {
        name: 'approve',
        label: 'Approve',
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

const ENDPOINTS = {
    record: '/panel/actions/record',
    bulk: '/panel/actions/bulk',
    reorder: '/panel/actions/reorder',
    cell: '/panel/actions/cell',
    table: '/panel/actions/table',
    form: '/panel/actions/form',
    infolist: '/panel/actions/infolist',
};

const RELATION_ENDPOINTS = {
    form: '/panel/relations/form',
    save: '/panel/relations/form',
    action: '/panel/relations/action',
    bulk: '/panel/relations/bulk',
    actionForm:
        '/panel/relations/action-form?resource=devices&record=1&relation=logs',
};

function resource() {
    return useActions(
        () => 'devices',
        () => ENDPOINTS,
    );
}

function relation() {
    return useRelationActions(
        () => ({ resource: 'devices', record: 1, relation: 'logs' }),
        () => RELATION_ENDPOINTS,
    );
}

beforeEach(() => {
    post.mockClear();
    visit.mockClear();
});

/*
 * The predicate every entry point shares
 */

describe('modal intent', () => {
    it('is false for a plain action', () => {
        expect(opensModal(action())).toBe(false);
    });

    it('is true for declared modal copy', () => {
        expect(opensModal(action({ modal: MODAL }))).toBe(true);
    });

    it('is true for a confirmation', () => {
        expect(
            opensModal(
                action({
                    confirmation: {
                        heading: 'Sure?',
                        description: '',
                        button: 'Yes',
                    },
                }),
            ),
        ).toBe(true);
    });

    it('is true for a form', () => {
        expect(opensModal(action({ type: 'form', hasForm: true }))).toBe(true);
    });
});

/*
 * T1 / T3 — the bug itself, on the resource surface
 */

describe('a resource action', () => {
    it('runs immediately when it declared nothing to say', () => {
        const { runRecord } = resource();

        runRecord(action(), 7);

        expect(post).toHaveBeenCalledTimes(1);
    });

    it('does not execute a non-form action with modal copy before confirmation', () => {
        const { runRecord, pending } = resource();

        runRecord(action({ modal: MODAL }), 7);

        // PP-01, stated as a test. This used to POST on the first click.
        expect(post).not.toHaveBeenCalled();
        expect(pending.value).not.toBeNull();
    });

    it('executes exactly once when confirmed', () => {
        const { runRecord, confirm } = resource();

        runRecord(action({ modal: MODAL }), 7);
        confirm();

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][1]).toMatchObject({
            action: 'approve',
            record: 7,
        });
    });

    it('never executes when cancelled', () => {
        const { runRecord, cancel, pending } = resource();

        runRecord(action({ modal: MODAL }), 7);
        cancel();

        expect(post).not.toHaveBeenCalled();
        expect(pending.value).toBeNull();
    });

    it('holds a modal table action', () => {
        const { runTable } = resource();

        runTable(action({ modal: MODAL }));

        expect(post).not.toHaveBeenCalled();
    });

    it('holds a modal bulk action and keeps the selection', () => {
        const { runBulk, confirm } = resource();

        runBulk(action({ modal: MODAL }), [1, 2, 3]);

        expect(post).not.toHaveBeenCalled();

        confirm();

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][1]).toMatchObject({ records: [1, 2, 3] });
    });

    it('still navigates for a link action', () => {
        const { runRecord } = resource();

        runRecord(action({ type: 'link', url: '/somewhere', modal: MODAL }), 7);

        // A link goes somewhere; there is nothing to confirm about leaving.
        expect(visit).toHaveBeenCalledWith('/somewhere');
        expect(post).not.toHaveBeenCalled();
    });
});

/*
 * T13 / T14 — relation surfaces, through the same shared predicate
 */

describe('a relation action', () => {
    it('holds a modal record action', () => {
        const { runRecord, confirm } = relation();

        runRecord(action({ modal: MODAL }), 42);

        expect(post).not.toHaveBeenCalled();

        confirm();

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][1]).toMatchObject({
            scope: 'record',
            related: 42,
        });
    });

    it('holds a modal header action', () => {
        const { runHeader, confirm } = relation();

        runHeader(action({ modal: MODAL }));

        expect(post).not.toHaveBeenCalled();

        confirm();

        expect(post.mock.calls[0][1]).toMatchObject({ scope: 'table' });
    });

    it('holds a modal bulk action', () => {
        const { runBulk } = relation();

        runBulk(action({ modal: MODAL }), [5, 6]);

        expect(post).not.toHaveBeenCalled();
    });

    it('still runs a plain relation action immediately', () => {
        const { runRecord } = relation();

        runRecord(action(), 42);

        expect(post).toHaveBeenCalledTimes(1);
    });
});

/*
 * T16 — the infolist surface uses the same contract
 */

describe('an infolist action', () => {
    it('holds a modal action', () => {
        const { run, confirm } = useInfolistActions(
            () => 'devices',
            () => ENDPOINTS,
            () => 7,
        );

        run(action({ modal: MODAL }));

        expect(post).not.toHaveBeenCalled();

        confirm();

        expect(post).toHaveBeenCalledTimes(1);
    });

    it('still runs a plain action immediately', () => {
        const { run } = useInfolistActions(
            () => 'devices',
            () => ENDPOINTS,
            () => 7,
        );

        run(action());

        expect(post).toHaveBeenCalledTimes(1);
    });
});
