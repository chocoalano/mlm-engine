/**
 * @vitest-environment happy-dom
 */
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { CalloutDefinition } from '@/panel/types/form';
import type {
    ConstraintDefinition,
    QueryBuilderFilterDefinition,
    QueryBuilderRule,
} from '@/panel/types/table';

/**
 * Which columns a table can be filtered by, and what it says above its rows.
 *
 * The condition list used to be a second declaration beside the columns, so a
 * column somebody added was a column they could see and could not filter by.
 * It is derived from the columns now, and — the part these assert — the
 * *choices* follow what is on screen: hiding a column takes it out of "Add
 * condition" without touching a condition already built on it.
 *
 * That distinction is the whole design. Hiding a display column is a change
 * to what the reader is looking at; silently deleting their filter would be a
 * change to what the table is *showing them*, which is not the same thing and
 * not what they asked for.
 */
vi.mock('@inertiajs/vue3', () => ({
    router: {
        visit: vi.fn(),
        post: vi.fn(),
        reload: vi.fn(),
        on: () => () => {},
    },
    usePage: () => ({
        props: {
            translations: {
                tables: {
                    column: 'Column',
                    condition: 'Condition',
                    add_condition: 'Add condition',
                    max_conditions: 'Up to :count conditions.',
                    no_queryable_columns: 'No columns available to filter by.',
                    rule_value: 'Value for :rule',
                    rule: 'rule',
                    remove_rule: 'Remove rule :number',
                },
                forms: { pick_a_date: 'Pick a date', clear_date: 'Clear date' },
            },
        },
        url: '/',
        component: '',
        version: null,
    }),
    Link: { name: 'Link', template: '<a><slot /></a>' },
}));

const { default: DataTableQueryBuilder } =
    await import('@/panel/tables/DataTableQueryBuilder.vue');
const { default: DataTableIntro } =
    await import('@/panel/tables/DataTableIntro.vue');

const mounted: Array<{ unmount: () => void }> = [];

afterEach(() => {
    while (mounted.length > 0) {
        mounted.pop()?.unmount();
    }

    document.body.innerHTML = '';
});

function constraint(
    name: string,
    label: string,
    input: 'text' | 'number' | 'date' | 'none' = 'text',
): ConstraintDefinition {
    return {
        name,
        label,
        input,
        operators: [
            { value: 'equals', label: 'is', needsValue: true },
            { value: 'is_blank', label: 'is blank', needsValue: false },
        ],
    } as unknown as ConstraintDefinition;
}

function filterWith(
    constraints: ConstraintDefinition[],
): QueryBuilderFilterDefinition {
    return {
        name: 'advanced',
        label: 'Advanced',
        type: 'query_builder',
        maxRules: 5,
        constraints,
    } as unknown as QueryBuilderFilterDefinition;
}

function builder(options: {
    constraints: ConstraintDefinition[];
    rules?: QueryBuilderRule[];
    columnNames?: string[];
    visibleColumns?: string[];
}) {
    const wrapper = mount(DataTableQueryBuilder, {
        attachTo: document.body,
        props: {
            filter: filterWith(options.constraints),
            rules: options.rules ?? [],
            columnNames: options.columnNames ?? [],
            visibleColumns: options.visibleColumns ?? [],
        },
    });

    mounted.push(wrapper);

    return wrapper;
}

/**
 * Whether the builder offers an "Add condition" control at all.
 *
 * A closed Reka select renders none of its items, so what the dropdown
 * *lists* is a question for Chrome (`tests/browser/datatable.mjs`). What it
 * would *do* is observable here, and it is the same decision: `addRule()`
 * takes the first selectable constraint, so which one it picks says which
 * ones are on offer.
 */
function addButton(wrapper: ReturnType<typeof builder>) {
    return wrapper
        .findAll('button')
        .find((candidate) => candidate.text().includes('Add condition'));
}

/** What "Add condition" would create, read off the emitted change. */
async function added(wrapper: ReturnType<typeof builder>) {
    const button = wrapper
        .findAll('button')
        .find((candidate) => candidate.text().includes('Add condition'));

    await button?.trigger('click');

    const changes = (wrapper.emitted('change') ?? []) as Array<
        [QueryBuilderRule[]]
    >;

    return changes[0]?.[0] ?? [];
}

/*
 * Q1-Q3, Q7 — which columns become conditions
 */

describe('condition choices follow the visible columns', () => {
    it('Q1 — offers a visible text column', async () => {
        const wrapper = builder({
            constraints: [constraint('name', 'Name')],
            columnNames: ['name'],
            visibleColumns: ['name'],
        });

        expect(await added(wrapper)).toEqual([
            { column: 'name', operator: 'equals', value: null },
        ]);
    });

    it('Q4 — skips a hidden column when adding', async () => {
        // `name` comes first in the constraint list, so a builder that
        // ignored visibility would pick it. Only `email` is on screen.
        const wrapper = builder({
            constraints: [
                constraint('name', 'Name'),
                constraint('email', 'Email'),
            ],
            columnNames: ['name', 'email'],
            visibleColumns: ['email'],
        });

        expect(await added(wrapper)).toEqual([
            { column: 'email', operator: 'equals', value: null },
        ]);
    });

    it('Q5 — offers a column again once it is shown', async () => {
        const wrapper = builder({
            constraints: [constraint('email', 'Email')],
            columnNames: ['email'],
            visibleColumns: [],
        });

        expect(addButton(wrapper)).toBeUndefined();

        await wrapper.setProps({ visibleColumns: ['email'] });

        // Reactive: no reload, no remount — the same component answers
        // differently because the table's state changed.
        expect(addButton(wrapper)).toBeDefined();
        expect(await added(wrapper)).toEqual([
            { column: 'email', operator: 'equals', value: null },
        ]);
    });

    it('Q7 — a constraint with no column of its name is always offered', async () => {
        // A developer declared it deliberately; there is nothing on screen to
        // hide, and dropping it would break tables written before columns
        // could describe themselves.
        const wrapper = builder({
            constraints: [
                constraint('name', 'Name'),
                constraint('internal_note', 'Internal note'),
            ],
            columnNames: ['name'],
            visibleColumns: [],
        });

        expect(await added(wrapper)).toEqual([
            { column: 'internal_note', operator: 'equals', value: null },
        ]);
    });
});

/*
 * Q6 — an existing condition survives its column being hidden
 */

describe('hiding a column does not rewrite an existing condition', () => {
    it('Q6 — keeps the rule', async () => {
        const wrapper = builder({
            constraints: [constraint('email', 'Email')],
            rules: [
                { column: 'email', operator: 'equals', value: 'a@example.com' },
            ],
            columnNames: ['email'],
            visibleColumns: ['email'],
        });

        await wrapper.setProps({ visibleColumns: [] });

        // No `change` was emitted: the filter the user built is theirs, and
        // hiding a display column is not a request to delete it.
        expect(wrapper.emitted('change')).toBeUndefined();
    });

    it('Q6 — still renders the rule and its value control', async () => {
        const wrapper = builder({
            constraints: [constraint('email', 'Email')],
            rules: [
                { column: 'email', operator: 'equals', value: 'a@example.com' },
            ],
            columnNames: ['email'],
            visibleColumns: ['email'],
        });

        await wrapper.setProps({ visibleColumns: [] });

        // The row is still there to be read and edited. That its select can
        // still *list* its own hidden column is proven in Chrome, where a
        // dropdown can actually be opened.
        expect(wrapper.findAllComponents({ name: 'Select' }).length).toBe(2);
        expect((wrapper.find('input').element as HTMLInputElement).value).toBe(
            'a@example.com',
        );
    });
});

/*
 * The empty state
 */

describe('when nothing can be filtered by', () => {
    it('says so instead of offering a dead control', () => {
        const wrapper = builder({
            constraints: [constraint('name', 'Name')],
            columnNames: ['name'],
            visibleColumns: [],
        });

        expect(wrapper.text()).toContain('No columns available to filter by.');
        expect(wrapper.text()).not.toContain('Add condition');
    });

    it('translates the rule ceiling rather than hardcoding it', () => {
        const wrapper = builder({
            constraints: [constraint('name', 'Name')],
            rules: Array.from({ length: 5 }, () => ({
                column: 'name',
                operator: 'equals',
                value: 'x',
            })),
            columnNames: ['name'],
            visibleColumns: ['name'],
        });

        // This sentence used to be an English literal in the template.
        expect(wrapper.text()).toContain('Up to 5 conditions.');
    });
});

/*
 * D1-D3, C1-C6 — description and callouts
 */

function intro(description: string | null, callouts: CalloutDefinition[] = []) {
    const wrapper = mount(DataTableIntro, {
        attachTo: document.body,
        props: { description, callouts },
    });

    mounted.push(wrapper);

    return wrapper;
}

function callout(
    body: string,
    tone = 'info',
    heading: string | null = null,
): CalloutDefinition {
    return {
        component: 'callout',
        body,
        heading,
        tone,
        icon: null,
        schema: [],
    } as unknown as CalloutDefinition;
}

describe('what a table says above its rows', () => {
    it('D1/C6 — renders nothing when there is nothing to say', () => {
        const wrapper = intro(null);

        expect(wrapper.html()).toBe('<!--v-if-->');
    });

    it('D2 — renders a description', () => {
        expect(intro('Excludes archived records.').text()).toContain(
            'Excludes archived records.',
        );
    });

    it('D3 — gives the description an id per instance', () => {
        // Both in one app: `useId()` counts per application, so two separate
        // `mount()` calls would each start at zero and the ids would collide
        // for a reason that has nothing to do with the component.
        const wrapper = mount(
            {
                components: { DataTableIntro },
                template:
                    '<div>' +
                    '<DataTableIntro description="One" :callouts="[]" />' +
                    '<DataTableIntro description="Two" :callouts="[]" />' +
                    '</div>',
            },
            { attachTo: document.body },
        );
        mounted.push(wrapper);

        const ids = [...document.querySelectorAll('p[id]')].map((p) => p.id);

        expect(ids).toHaveLength(2);
        expect(new Set(ids).size).toBe(2);
    });

    it('C1 — renders an info callout', () => {
        expect(
            intro(null, [callout('Synced every 15 minutes.')]).text(),
        ).toContain('Synced every 15 minutes.');
    });

    it('C2 — renders a warning callout', () => {
        const wrapper = intro(null, [
            callout('Payroll period is locked.', 'warning'),
        ]);

        expect(wrapper.text()).toContain('Payroll period is locked.');
    });

    it('C4 — stacks several callouts in order', () => {
        const wrapper = intro(null, [
            callout('First'),
            callout('Second', 'warning'),
        ]);

        const text = wrapper.text();

        expect(text.indexOf('First')).toBeLessThan(text.indexOf('Second'));
    });

    it('C7 — does not announce itself as an alert', () => {
        // Standing copy, not an interruption. `role="alert"` here would make
        // every table load shout at somebody using a screen reader.
        intro('Context.', [callout('A notice.')]);

        expect(document.querySelector('[role="alert"]')).toBeNull();
    });

    it('renders the description before the callouts', () => {
        const wrapper = intro('Context first.', [callout('Notice second.')]);
        const text = wrapper.text();

        expect(text.indexOf('Context first.')).toBeLessThan(
            text.indexOf('Notice second.'),
        );
    });
});
