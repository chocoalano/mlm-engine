/**
 * @vitest-environment happy-dom
 */
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type {
    FilterDefinition,
    QueryBuilderRule,
    TableDefinition,
    TableState,
} from '@/panel/types/table';

/**
 * "Add condition", on a table that filters immediately.
 *
 * A newly added rule has no value yet, so the server refuses it — correctly,
 * because a comparison with nothing to compare against is not a condition.
 * The bug was what happened next: the editor rendered straight from the
 * server's answer, so the row the user had just added to fill in disappeared
 * under them before they could type. "Add condition" was unusable unless the
 * whole table was switched to deferred filtering.
 *
 * These mount the real toolbar and drive it the way a user does, because the
 * failure is precisely the round trip — an assertion about `addRule()`
 * returning an array of length one would have passed throughout.
 */
vi.mock('@inertiajs/vue3', () => ({
    router: {
        post: vi.fn(),
        visit: vi.fn(),
        reload: vi.fn(),
        on: () => () => {},
    },
    usePage: () => ({ props: {}, url: '/', component: '', version: null }),
    Link: { name: 'Link', template: '<a><slot /></a>' },
}));

const { default: DataTableToolbar } =
    await import('@/panel/tables/DataTableToolbar.vue');

const FILTER: FilterDefinition = {
    name: 'advanced',
    label: 'Advanced',
    type: 'query_builder',
    maxRules: 5,
    constraints: [
        {
            name: 'status',
            label: 'Status',
            input: 'text',
            operators: [
                { value: 'equals', label: 'is', needsValue: true },
                { value: 'is_filled', label: 'is filled', needsValue: false },
            ],
        },
        {
            name: 'created_at',
            label: 'Created at',
            input: 'date',
            operators: [
                { value: 'equals', label: 'is', needsValue: true },
                { value: 'is_blank', label: 'is blank', needsValue: false },
            ],
        },
    ],
} as unknown as FilterDefinition;

function table(deferred = false): TableDefinition {
    return {
        columns: [],
        filters: [FILTER],
        recordActions: [],
        bulkActions: [],
        headerActions: [],
        toolbarActions: [],
        emptyStateActions: [],
        searchable: false,
        filterBehaviour: {
            deferred,
            triggerIcon: null,
            triggerLabel: 'Filters',
            applyLabel: 'Apply',
            resetLabel: 'Reset',
        },
    } as unknown as TableDefinition;
}

function state(filters: Record<string, unknown> = {}): TableState {
    return {
        search: '',
        sort: null,
        direction: 'asc',
        page: 1,
        perPage: 10,
        filters,
        columnSearches: {},
        filterIndicators: [],
        columns: { visible: [], searches: {} },
    } as unknown as TableState;
}

function render(deferred = false, filters: Record<string, unknown> = {}) {
    return mount(DataTableToolbar, {
        props: { table: table(deferred), state: state(filters) },
        global: { stubs: { ActionButton: true, DataTableSortMenu: true } },
    });
}

/** Opens the filter popover so the query builder renders. */
async function openFilters(wrapper: ReturnType<typeof render>) {
    const trigger = [
        ...document.body.querySelectorAll('button'),
        ...wrapper.findAll('button').map((b) => b.element),
    ].find((el) => (el.textContent ?? '').includes('Filters'));

    (trigger as HTMLButtonElement | undefined)?.click();

    await wrapper.vm.$nextTick();
    await wrapper.vm.$nextTick();
}

/** The rules the query builder is currently showing. */
function shownRules(wrapper: ReturnType<typeof render>): QueryBuilderRule[] {
    const builder = wrapper.findComponent({ name: 'DataTableQueryBuilder' });

    return builder.exists()
        ? (builder.props('rules') as QueryBuilderRule[])
        : [];
}

function emittedFilters(wrapper: ReturnType<typeof render>): unknown[] {
    return (wrapper.emitted('filter') ?? []).map(
        (call) => (call as unknown[])[1],
    );
}

beforeEach(() => {
    document.body.innerHTML = '';
});

describe('adding a condition in immediate mode', () => {
    it('keeps a newly added incomplete query-builder condition visible', async () => {
        const wrapper = render();

        await openFilters(wrapper);

        const builder = wrapper.findComponent({
            name: 'DataTableQueryBuilder',
        });

        expect(builder.exists()).toBe(true);

        // The click the user makes.
        builder.vm.$emit('change', [
            { column: 'status', operator: 'equals', value: null },
        ]);

        await wrapper.vm.$nextTick();

        // The server refuses the incomplete rule, so its answer carries none.
        await wrapper.setProps({ table: table(), state: state({}) });

        // The row is still there to be filled in. This is PP-27.
        expect(shownRules(wrapper)).toEqual([
            { column: 'status', operator: 'equals', value: null },
        ]);
    });

    it('sends the server nothing for a rule it could not run', async () => {
        const wrapper = render();

        await openFilters(wrapper);

        wrapper
            .findComponent({ name: 'DataTableQueryBuilder' })
            .vm.$emit('change', [
                { column: 'status', operator: 'equals', value: null },
            ]);

        await wrapper.vm.$nextTick();

        // Not an incomplete rule the server has to reject — nothing at all.
        expect(emittedFilters(wrapper)).toEqual([null]);
    });

    it('applies the condition as soon as it is complete', async () => {
        const wrapper = render();

        await openFilters(wrapper);

        const builder = wrapper.findComponent({
            name: 'DataTableQueryBuilder',
        });

        builder.vm.$emit('change', [
            { column: 'status', operator: 'equals', value: null },
        ]);
        await wrapper.vm.$nextTick();

        builder.vm.$emit('change', [
            { column: 'status', operator: 'equals', value: 'active' },
        ]);
        await wrapper.vm.$nextTick();

        expect(emittedFilters(wrapper)[1]).toEqual([
            { column: 'status', operator: 'equals', value: 'active' },
        ]);
    });

    it('keeps the applied condition after the server answers', async () => {
        const applied = [
            { column: 'status', operator: 'equals', value: 'active' },
        ];
        const wrapper = render(false, { advanced: applied });

        await openFilters(wrapper);

        expect(shownRules(wrapper)).toEqual(applied);
    });

    it('shows a complete and an incomplete rule side by side', async () => {
        const complete = {
            column: 'status',
            operator: 'equals',
            value: 'active',
        };
        const draft = { column: 'status', operator: 'equals', value: null };

        const wrapper = render(false, { advanced: [complete] });

        await openFilters(wrapper);

        wrapper
            .findComponent({ name: 'DataTableQueryBuilder' })
            .vm.$emit('change', [complete, draft]);

        await wrapper.vm.$nextTick();

        // Only the runnable one is sent...
        expect(emittedFilters(wrapper)).toEqual([[complete]]);

        // ...and the server's answer reflects that.
        await wrapper.setProps({
            table: table(),
            state: state({ advanced: [complete] }),
        });

        // Both are still on screen.
        expect(shownRules(wrapper)).toEqual([complete, draft]);
    });

    it('forgets the draft once every rule is runnable', async () => {
        const wrapper = render();

        await openFilters(wrapper);

        const builder = wrapper.findComponent({
            name: 'DataTableQueryBuilder',
        });
        const rule = { column: 'status', operator: 'equals', value: 'active' };

        builder.vm.$emit('change', [{ ...rule, value: null }]);
        await wrapper.vm.$nextTick();

        builder.vm.$emit('change', [rule]);
        await wrapper.vm.$nextTick();

        // The server is now the single source for this filter; a retained
        // copy would only be a way for the two to disagree.
        await wrapper.setProps({
            table: table(),
            state: state({ advanced: [rule] }),
        });

        expect(shownRules(wrapper)).toEqual([rule]);
    });

    it('treats an operator that needs no value as complete', async () => {
        const wrapper = render();

        await openFilters(wrapper);

        const rule = { column: 'status', operator: 'is_filled', value: null };

        wrapper
            .findComponent({ name: 'DataTableQueryBuilder' })
            .vm.$emit('change', [rule]);
        await wrapper.vm.$nextTick();

        // `needsValue: false`, so it is runnable immediately and goes straight
        // to the server rather than being held back.
        expect(emittedFilters(wrapper)).toEqual([[rule]]);
    });

    it('lets a draft row be removed again', async () => {
        const wrapper = render();

        await openFilters(wrapper);

        const builder = wrapper.findComponent({
            name: 'DataTableQueryBuilder',
        });

        builder.vm.$emit('change', [
            { column: 'status', operator: 'equals', value: null },
        ]);
        await wrapper.vm.$nextTick();

        builder.vm.$emit('change', []);
        await wrapper.vm.$nextTick();

        await wrapper.setProps({ table: table(), state: state({}) });

        expect(shownRules(wrapper)).toEqual([]);
    });

    it('still removes a complete rule immediately', async () => {
        const applied = [
            { column: 'status', operator: 'equals', value: 'active' },
        ];
        const wrapper = render(false, { advanced: applied });

        await openFilters(wrapper);

        wrapper
            .findComponent({ name: 'DataTableQueryBuilder' })
            .vm.$emit('change', []);
        await wrapper.vm.$nextTick();

        expect(emittedFilters(wrapper)).toEqual([null]);
    });
});

describe('a table that defers its filters', () => {
    it('holds everything locally, complete or not', async () => {
        const wrapper = render(true);

        await openFilters(wrapper);

        const draft = { column: 'status', operator: 'equals', value: null };

        wrapper
            .findComponent({ name: 'DataTableQueryBuilder' })
            .vm.$emit('change', [draft]);
        await wrapper.vm.$nextTick();

        // Nothing is sent until Apply — unchanged.
        expect(wrapper.emitted('filter')).toBeUndefined();
        expect(shownRules(wrapper)).toEqual([draft]);
    });
});

/**
 * The same round trip for a date rule.
 *
 * A date value is now picked from `PanelDatePicker` rather than typed into a
 * native date input, and the picker publishes `null` where the input
 * published `''`. Both are incomplete, and PP-27 has to hold for both — the
 * draft row is the whole reason "Add condition" works in immediate mode.
 */
describe('a date condition keeps the same draft behaviour', () => {
    it('keeps an incomplete date rule visible', async () => {
        const wrapper = render();

        await openFilters(wrapper);

        wrapper
            .findComponent({ name: 'DataTableQueryBuilder' })
            .vm.$emit('change', [
                { column: 'created_at', operator: 'equals', value: null },
            ]);

        await wrapper.vm.$nextTick();
        await wrapper.setProps({ table: table(), state: state({}) });

        expect(shownRules(wrapper)).toEqual([
            { column: 'created_at', operator: 'equals', value: null },
        ]);
    });

    it('sends the server nothing while the date is unpicked', async () => {
        const wrapper = render();

        await openFilters(wrapper);

        wrapper
            .findComponent({ name: 'DataTableQueryBuilder' })
            .vm.$emit('change', [
                { column: 'created_at', operator: 'equals', value: null },
            ]);

        await wrapper.vm.$nextTick();

        expect(emittedFilters(wrapper)).toEqual([null]);
    });

    it('applies the condition once a date is picked', async () => {
        const wrapper = render();

        await openFilters(wrapper);

        const builder = wrapper.findComponent({
            name: 'DataTableQueryBuilder',
        });

        builder.vm.$emit('change', [
            { column: 'created_at', operator: 'equals', value: null },
        ]);
        await wrapper.vm.$nextTick();

        builder.vm.$emit('change', [
            { column: 'created_at', operator: 'equals', value: '2026-09-10' },
        ]);
        await wrapper.vm.$nextTick();

        expect(emittedFilters(wrapper)[1]).toEqual([
            { column: 'created_at', operator: 'equals', value: '2026-09-10' },
        ]);
    });

    it('drops a stale date when the operator stops needing one', async () => {
        const wrapper = render();

        await openFilters(wrapper);

        const builder = wrapper.findComponent({
            name: 'DataTableQueryBuilder',
        });

        builder.vm.$emit('change', [
            { column: 'created_at', operator: 'equals', value: '2026-09-10' },
        ]);
        await wrapper.vm.$nextTick();

        // `is_blank` needs no value, so the date that was there must not ride
        // along into the executable rule.
        builder.vm.$emit('change', [
            { column: 'created_at', operator: 'is_blank', value: null },
        ]);
        await wrapper.vm.$nextTick();

        expect(emittedFilters(wrapper)[1]).toEqual([
            { column: 'created_at', operator: 'is_blank', value: null },
        ]);
    });
});
