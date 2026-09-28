/**
 * @vitest-environment happy-dom
 */
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type {
    FieldDefinition,
    FormComponentDefinition,
} from '@/panel/types/form';

/**
 * What a searchable Select says when the list cannot be fetched.
 *
 * It said nothing. `run()` returned without touching the options when the
 * request failed, and the comment explained why — an empty list would read as
 * "nothing matches", which is a different and wrong answer. That reasoning is
 * right and the outcome was still wrong: a search for "bob" that the server
 * refused left Alice and Alina on screen, settled and unlabelled, and somebody
 * could pick one believing it matched what they typed.
 */
const { fetchOptions } = vi.hoisted(() => ({
    fetchOptions: vi.fn<(...args: unknown[]) => Promise<unknown>>(),
}));

vi.mock('@/panel/forms/optionsEndpoint', async (original) => ({
    ...(await original<Record<string, unknown>>()),
    fetchOptions: (...args: unknown[]) => fetchOptions(...args),
}));

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
                forms: {
                    select_placeholder: 'Select...',
                    select_empty: 'Nothing to choose from.',
                    select_more: '+:count more',
                    select_no_matches: 'Nothing matches that search.',
                    select_failed: 'The list could not be loaded.',
                    select_stale:
                        'Showing earlier results — the update failed.',
                    search_field: 'Search :field',
                    retry: 'Try again',
                },
            },
        },
        url: '/',
        component: '',
        version: null,
    }),
    Link: { name: 'Link', template: '<a><slot /></a>' },
}));

const { default: FormRenderer } =
    await import('@/panel/forms/FormRenderer.vue');

const mounted: Array<{ unmount: () => void }> = [];

beforeEach(() => {
    fetchOptions.mockReset();
});

afterEach(() => {
    while (mounted.length > 0) {
        mounted.pop()?.unmount();
    }

    document.body.innerHTML = '';
});

function select(overrides: Record<string, unknown> = {}): FieldDefinition {
    return {
        component: 'field',
        name: 'owner',
        label: 'Owner',
        type: 'select',
        value: null,
        placeholder: null,
        helperText: null,
        required: false,
        disabled: false,
        inlineLabel: false,
        columnSpan: 'full',
        conditions: { visibleWhen: [], hiddenWhen: [] },
        live: null,
        validation: { required: false },
        multiple: true,
        searchable: true,
        dependentOptions: false,
        options: [
            { value: 'alice', label: 'Alice' },
            { value: 'alina', label: 'Alina' },
        ],
        ...overrides,
    } as unknown as FieldDefinition;
}

function render(field: FieldDefinition, props: Record<string, unknown> = {}) {
    const wrapper = mount(FormRenderer, {
        attachTo: document.body,
        props: {
            form: {
                schema: [field as unknown as FormComponentDefinition],
                columns: 1,
            },
            submitUrl: '/panel/things',
            optionsUrl: '/panel/options',
            ...props,
        },
    });

    mounted.push(wrapper);

    return wrapper;
}

/*
 * The search lives in the combobox's popup, which is portalled to the body:
 * everything inside it is read from `document`, not from the wrapper.
 */
function trigger(wrapper: ReturnType<typeof render>) {
    return wrapper.get('button[role="combobox"]');
}

function searchBox(): HTMLInputElement | null {
    return document.querySelector<HTMLInputElement>('input[role="combobox"]');
}

async function open(wrapper: ReturnType<typeof render>) {
    if (searchBox() === null) {
        await trigger(wrapper).trigger('click');
        await flushPromises();
    }
}

async function type(wrapper: ReturnType<typeof render>, term: string) {
    await open(wrapper);

    const input = searchBox() as HTMLInputElement;

    input.value = term;
    input.dispatchEvent(new Event('input'));

    await flushPromises();
    await new Promise((resolve) => setTimeout(resolve, 300));
    await flushPromises();
}

function options(): HTMLElement[] {
    return [...document.querySelectorAll<HTMLElement>('[role="option"]')];
}

function labels(): string[] {
    return options().map((option) => option.textContent?.trim() ?? '');
}

function button(text: string): HTMLButtonElement {
    const found = [...document.querySelectorAll('button')].find(
        (candidate) => candidate.textContent?.trim() === text,
    );

    if (found === undefined) {
        throw new Error(`No button reads "${text}".`);
    }

    return found;
}

/*
 * T26 / T27 / T28 / T29 / T30 / T31
 */

describe('a searchable select', () => {
    it('says it is working while it asks', async () => {
        let settle: (value: unknown) => void = () => {};

        fetchOptions.mockImplementation(
            () => new Promise((resolve) => (settle = resolve)),
        );

        const wrapper = render(select());

        await type(wrapper, 'bo');

        expect(searchBox()?.getAttribute('aria-busy')).toBe('true');

        settle([{ value: 'bob', label: 'Bob' }]);
        await flushPromises();

        expect(searchBox()?.hasAttribute('aria-busy')).toBe(false);
    });

    it('separates a search that matched nothing from one that failed', async () => {
        fetchOptions.mockResolvedValue([]);

        const wrapper = render(select());

        await type(wrapper, 'zz');

        expect(document.body.textContent).toContain(
            'Nothing matches that search.',
        );
        expect(document.body.textContent).not.toContain('could not be loaded');
    });

    it("does not present an earlier query's options as this one's", async () => {
        fetchOptions.mockResolvedValueOnce([
            { value: 'alice', label: 'Alice' },
            { value: 'alina', label: 'Alina' },
        ]);

        const wrapper = render(select());

        await type(wrapper, 'ali');

        expect(labels()).toEqual(['Alice', 'Alina']);

        fetchOptions.mockResolvedValueOnce(null);

        await type(wrapper, 'bob');

        // Alice and Alina are still readable — blanking the list would read as
        // "nothing matches bob", which is a claim nobody made — but the field
        // now says they are not the answer to this query.
        expect(labels()).toEqual(['Alice', 'Alina']);
        expect(document.body.textContent).toContain('Showing earlier results');
    });

    it('offers a retry that asks for the same term', async () => {
        fetchOptions.mockResolvedValueOnce(null);

        const wrapper = render(select());

        await type(wrapper, 'bob');

        expect(document.body.textContent).toContain(
            'The list could not be loaded.',
        );

        fetchOptions.mockResolvedValueOnce([{ value: 'bob', label: 'Bob' }]);

        button('Try again').click();
        await flushPromises();

        expect(fetchOptions.mock.calls.at(-1)?.[2]).toBe('bob');
        expect(labels()).toContain('Bob');
    });

    it('keeps a chosen value when the list fails to load', async () => {
        fetchOptions.mockResolvedValueOnce(null);

        const wrapper = render(select({ value: ['alice'] }));

        await type(wrapper, 'bob');

        // A network failure must not quietly unpick what the user already
        // chose: the selection and the option list are different things.
        const checked = options().filter(
            (option) => option.getAttribute('aria-selected') === 'true',
        );

        expect(checked).toHaveLength(1);
        // And the chosen option stays in the list whatever the search did, or
        // the control would lose its own label.
        expect(labels()).toContain('Alice');
        expect(trigger(wrapper).text()).toContain('Alice');
    });

    it('retries a dependent search against the current form state', async () => {
        fetchOptions.mockResolvedValueOnce(null);

        const wrapper = render({
            component: 'grid',
            columns: 1,
            schema: [
                {
                    component: 'field',
                    name: 'team',
                    label: 'Team',
                    type: 'text',
                    inputType: 'text',
                    maxLength: null,
                    value: 'blue',
                    placeholder: null,
                    helperText: null,
                    required: false,
                    disabled: false,
                    inlineLabel: false,
                    columnSpan: 'full',
                    conditions: { visibleWhen: [], hiddenWhen: [] },
                    live: null,
                    validation: { required: false },
                },
                select({ dependentOptions: true }),
            ],
        } as unknown as FieldDefinition);

        await type(wrapper, 'bob');

        fetchOptions.mockResolvedValueOnce([{ value: 'bob', label: 'Bob' }]);

        // The parent changed between the failure and the retry. The retry must
        // ask with what the form holds now, not with what it held when the
        // first attempt was made — this is the PP-20 dependent-select contract.
        await wrapper.get('input[type="text"]').setValue('red');
        await flushPromises();

        button('Try again').click();
        await flushPromises();

        expect(fetchOptions.mock.calls.at(-1)?.[3]).toMatchObject({
            team: 'red',
        });
    });
});

/*
 * The combobox: a searchable select reads like any other select until it is
 * opened, and the search it opens onto is still the server's.
 */

describe('a searchable select as a combobox', () => {
    it('lists what the server matched, even when the label does not contain the term', async () => {
        // The server searched a column the label does not show. reka-ui's
        // own filter would hide Bob for not containing "bob@"; it is off.
        fetchOptions.mockResolvedValueOnce([{ value: 'bob', label: 'Bob' }]);

        const wrapper = render(select({ multiple: false }));

        await type(wrapper, 'bob@example.test');

        expect(fetchOptions.mock.calls.at(-1)?.[2]).toBe('bob@example.test');
        expect(labels()).toEqual(['Bob']);
    });

    it('keeps the name of a record found by searching once the popup closes', async () => {
        fetchOptions.mockResolvedValueOnce([{ value: 'zed', label: 'Zed' }]);

        const wrapper = render(select({ multiple: false }));

        await type(wrapper, 'ze');

        options()[0].click();
        await flushPromises();

        // Zed was never in the page the form arrived with. Closing dropped
        // the search and put that page back — the trigger still has to say
        // what was chosen, not fall back to the placeholder.
        expect(searchBox()).toBeNull();
        expect(trigger(wrapper).text()).toContain('Zed');
        expect(trigger(wrapper).text()).not.toContain('Select...');

        // And reopening lists it, marked as the choice, above the first page.
        await open(wrapper);

        expect(labels()).toEqual(['Zed', 'Alice', 'Alina']);
        expect(options()[0].getAttribute('aria-selected')).toBe('true');
    });

    it('never sends the chosen key to the server as a search', async () => {
        const wrapper = render(select({ multiple: false, value: 'alice' }));

        await open(wrapper);
        await new Promise((resolve) => setTimeout(resolve, 300));
        await flushPromises();

        expect(searchBox()?.value).toBe('');
        expect(fetchOptions).not.toHaveBeenCalled();
    });

    it('is named by the field label, not by reka-ui', () => {
        const wrapper = render(select({ multiple: false }));

        const labelledBy = trigger(wrapper).attributes('aria-labelledby');

        expect(labelledBy).toBeDefined();
        expect(
            document.getElementById(labelledBy as string)?.textContent?.trim(),
        ).toBe('Owner');
    });

    it('summarises a long selection on the trigger', () => {
        const wrapper = render(
            select({
                value: ['a', 'b', 'c', 'd', 'e'],
                options: ['a', 'b', 'c', 'd', 'e'].map((value) => ({
                    value,
                    label: value.toUpperCase(),
                })),
            }),
        );

        const text = trigger(wrapper).text();

        expect(text).toContain('A');
        expect(text).toContain('C');
        expect(text).not.toContain('D');
        expect(text).toContain('+2 more');
    });

    it('stays open while several values are picked', async () => {
        fetchOptions.mockResolvedValueOnce([
            { value: 'bob', label: 'Bob' },
            { value: 'bea', label: 'Bea' },
        ]);

        const wrapper = render(select());

        await type(wrapper, 'b');

        options()[0].click();
        await flushPromises();

        // A multiple select keeps both the popup and the search open, so the
        // second pick comes from the same results as the first.
        expect(searchBox()?.value).toBe('b');
        expect(labels()).toContain('Bea');

        options()
            .find((option) => option.textContent?.trim() === 'Bea')
            ?.click();
        await flushPromises();

        expect(trigger(wrapper).text()).toContain('Bob');
        expect(trigger(wrapper).text()).toContain('Bea');
    });
});
