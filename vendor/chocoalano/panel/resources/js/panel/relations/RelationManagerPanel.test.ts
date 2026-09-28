/**
 * @vitest-environment happy-dom
 */
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ActionDefinition } from '@/panel/types/action';
import type { RelationDefinition } from '@/panel/types/relation';

/**
 * Which dialog a relation action opens, decided by clicking it.
 *
 * `useRelationActions` is tested on its own; what this covers is the routing
 * above it, which is the half that decides *whether the composable is asked
 * at all*. Both halves were wrong at once: the panel only opened a dialog for
 * an action that already carried a server-built `formUrl`, so an action
 * declaring its own schema fell through to "run it now" and a header action
 * declaring one reached nothing.
 *
 * Mounted rather than asserted on props: the failure was a click going to the
 * wrong branch, and a props snapshot cannot see a click.
 */
const post = vi.fn();
const visit = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    router: {
        post: (...args: unknown[]) => post(...args),
        visit: (...args: unknown[]) => visit(...args),
        reload: vi.fn(),
        on: () => () => {},
    },
    usePage: () => ({ props: {}, url: '/', component: '', version: null }),
    Link: { name: 'Link', template: '<a><slot /></a>' },
}));

// The table half of this panel is not what is under test, and mounting it
// would drag in the whole data-table stack to answer a question about a
// button. Stubbed by name so the action wiring stays real.
const STUBS = {
    DataTable: true,
    DataTableToolbar: true,
    DataTablePagination: true,
    DataTableBulkActions: true,
    DataTableColumnManager: true,
    RelationFormDialog: {
        name: 'RelationFormDialog',
        props: ['formUrl'],
        template: '<div />',
    },
    ActionModal: {
        name: 'ActionModal',
        props: ['action', 'processing', 'formUrl', 'context'],
        template: '<div />',
    },
};

const { default: RelationManagerPanel } =
    await import('@/panel/relations/RelationManagerPanel.vue');

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

function relation(headerActions: ActionDefinition[]): RelationDefinition {
    return {
        key: 'tasks',
        title: 'Tasks',
        icon: null,
        stateKey: 'relations.tasks',
        table: { columns: [], recordActions: [], bulkActions: [] } as never,
        state: {} as never,
        rows: [],
        summaries: {} as never,
        groupSummaries: {} as never,
        pagination: {
            page: 1,
            perPage: 10,
            total: 0,
            lastPage: 1,
            from: 0,
            to: 0,
        },
        headerActions,
        endpoints: {
            form: '/panel/relations/form',
            save: '/panel/relations/form',
            action: '/panel/relations/action',
            bulk: '/panel/relations/bulk',
            actionForm:
                '/panel/relations/action-form?resource=projects&record=1&relation=tasks',
        },
    };
}

function render(headerActions: ActionDefinition[]) {
    return mount(RelationManagerPanel, {
        props: {
            relation: relation(headerActions),
            resource: 'projects',
            record: 1,
        },
        global: { stubs: STUBS },
    });
}

/** The `formUrl` the action dialog is currently showing, if any. */
function modalFormUrl(wrapper: ReturnType<typeof render>): string | null {
    return wrapper.findComponent({ name: 'ActionModal' }).props('formUrl') as
        string | null;
}

function relationDialogUrl(wrapper: ReturnType<typeof render>): string | null {
    return wrapper
        .findComponent({ name: 'RelationFormDialog' })
        .props('formUrl') as string | null;
}

beforeEach(() => {
    post.mockClear();
    visit.mockClear();
});

describe('clicking a header action', () => {
    it('runs one with no form immediately', async () => {
        const wrapper = render([action({ name: 'archiveAll' })]);

        await wrapper.find('button').trigger('click');

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][1]).toMatchObject({
            scope: 'table',
            action: 'archiveAll',
        });
        expect(modalFormUrl(wrapper)).toBeNull();
    });

    it('opens the action dialog for one carrying its own schema', async () => {
        const wrapper = render([
            action({ name: 'addSpecial', type: 'form', hasForm: true }),
        ]);

        await wrapper.find('button').trigger('click');

        // The branch that was dead. Nothing ran, and the dialog is showing a
        // URL scoped to the relation rather than to a row.
        expect(post).not.toHaveBeenCalled();

        const url = new URL(
            modalFormUrl(wrapper) as string,
            'http://localhost',
        );

        expect(url.pathname).toBe('/panel/relations/action-form');
        expect(url.searchParams.get('action')).toBe('addSpecial');
        expect(url.searchParams.get('scope')).toBe('table');
        expect(url.searchParams.has('related')).toBe(false);
    });

    it('opens the relation form dialog for one of the built-in operations', async () => {
        const wrapper = render([
            action({
                name: 'create',
                type: 'form',
                formUrl: '/panel/relations/form?operation=create',
            }),
        ]);

        await wrapper.find('button').trigger('click');

        // Create, attach and associate name an owner and an operation, so the
        // server's own URL is used and a different dialog renders it.
        expect(post).not.toHaveBeenCalled();
        expect(relationDialogUrl(wrapper)).toBe(
            '/panel/relations/form?operation=create',
        );
        expect(modalFormUrl(wrapper)).toBeNull();
    });

    it('does not run a form action it cannot describe', async () => {
        const wrapper = render([
            action({ name: 'broken', type: 'form', hasForm: false }),
        ]);

        await wrapper.find('button').trigger('click');

        // Fail closed. Treating "no form URL" as "no form" is what ran the
        // handler with nothing in the first place.
        expect(post).not.toHaveBeenCalled();
        expect(modalFormUrl(wrapper)).toBeNull();
        expect(relationDialogUrl(wrapper)).toBeNull();
    });

    it('renders one button per header action the server sent', () => {
        const wrapper = render([
            action({ name: 'a', label: 'A' }),
            action({ name: 'b', label: 'B' }),
        ]);

        const labels = wrapper.findAll('button').map((b) => b.text());

        // An action the server filtered out is not here to be clicked, which
        // is the whole of the frontend's part in authorization.
        expect(labels).toEqual(['A', 'B']);
    });

    it('offers nothing when the server sent no header actions', () => {
        const wrapper = render([]);

        expect(wrapper.findAll('button')).toHaveLength(0);
    });
});
