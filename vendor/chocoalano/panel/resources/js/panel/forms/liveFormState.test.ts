/**
 * @vitest-environment happy-dom
 */
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type {
    FieldDefinition,
    FormComponentDefinition,
} from '@/panel/types/form';

/**
 * `live()`, driven the way a user drives it.
 *
 * Everything else about reactivity is asserted on the server: given this
 * state, the schema comes back like that. What no server test can see is
 * whether the browser ever asks — whether typing into a field marked `live()`
 * produces a request at all, whether the answer is applied, and whether a
 * field that never asked to be live stays silent.
 *
 * `fetch` is the boundary that gets mocked, through `postJson` — the module
 * that actually crosses it. Mocking `fetchFormState` would prove the renderer
 * calls a function; mocking here proves a request left, carrying what it
 * should.
 */
const postJson = vi.fn();

vi.mock('@/panel/forms/http', () => ({
    postJson: (...args: unknown[]) => postJson(...args),
    postForm: vi.fn(),
    csrfToken: () => 'test-token',
}));

vi.mock('@inertiajs/vue3', () => ({
    router: {
        visit: vi.fn(),
        post: vi.fn(),
        reload: vi.fn(),
        on: () => () => {},
    },
    usePage: () => ({ props: {}, url: '/', component: '', version: null }),
    Link: { name: 'Link', template: '<a><slot /></a>' },
}));

const { default: FormRenderer } =
    await import('@/panel/forms/FormRenderer.vue');

function field(overrides: Record<string, unknown> = {}): FieldDefinition {
    return {
        component: 'field',
        name: 'name',
        label: 'Name',
        type: 'text',
        inputType: 'text',
        maxLength: null,
        value: '',
        placeholder: null,
        helperText: null,
        required: false,
        disabled: false,
        inlineLabel: false,
        columnSpan: 'full',
        conditions: { visibleWhen: [], hiddenWhen: [] },
        live: null,
        validation: { required: false },
        ...overrides,
    } as unknown as FieldDefinition;
}

/** A field that asks the server to rebuild, with no debounce to wait out. */
function liveField(overrides: Record<string, unknown> = {}): FieldDefinition {
    return field({
        name: 'employment_type',
        label: 'Employment type',
        live: { onBlur: false, debounce: 0 },
        ...overrides,
    });
}

function render(
    schema: FormComponentDefinition[],
    props: Record<string, unknown> = {},
) {
    return mount(FormRenderer, {
        props: {
            form: { columns: 1, schema },
            submitUrl: '/panel/x',
            formStateUrl: '/panel/form-state?resource=employees&page=create',
            ...props,
        },
        global: { stubs: { Spinner: true } },
    });
}

/** Lets the debounce timer fire and the pending request resolve. */
async function settle(wrapper: { vm: { $nextTick: () => Promise<void> } }) {
    await new Promise((resolve) => setTimeout(resolve, 5));
    await wrapper.vm.$nextTick();
    await wrapper.vm.$nextTick();
}

beforeEach(() => {
    postJson.mockReset();
});

/*
 * T20 — the whole round trip, from a keystroke
 */

describe('a field marked live', () => {
    it('asks the server to rebuild, carrying what the form holds', async () => {
        postJson.mockResolvedValue({
            form: { columns: 1, schema: [liveField({ value: 'contract' })] },
            statePatch: {},
        });

        const wrapper = render([
            liveField(),
            field({ name: 'other', label: 'Other' }),
        ]);

        await wrapper.find('#other').setValue('typed already');
        await wrapper.find('#employment_type').setValue('contract');
        await settle(wrapper);

        expect(postJson).toHaveBeenCalledTimes(1);

        const [url, body] = postJson.mock.calls[0] as [
            string,
            { state: Record<string, unknown>; changed: string },
        ];

        // The URL is the server's statement of which form this is; the client
        // adds only the values and which field moved.
        expect(url).toBe('/panel/form-state?resource=employees&page=create');
        expect(body.changed).toBe('employment_type');
        expect(body.state).toMatchObject({
            employment_type: 'contract',
            other: 'typed already',
        });
    });

    it('renders the field the answer introduced', async () => {
        // The server decided a contract needs an end date. The browser had no
        // such field a moment ago.
        postJson.mockResolvedValue({
            form: {
                columns: 1,
                schema: [
                    liveField({ value: 'contract' }),
                    field({
                        name: 'contract_end_date',
                        label: 'Contract end date',
                    }),
                ],
            },
            statePatch: {},
        });

        const wrapper = render([liveField()]);

        expect(wrapper.find('#contract_end_date').exists()).toBe(false);

        await wrapper.find('#employment_type').setValue('contract');
        await settle(wrapper);

        expect(wrapper.find('#contract_end_date').exists()).toBe(true);
        expect(wrapper.text()).toContain('Contract end date');
    });

    it('removes a field the answer dropped', async () => {
        postJson.mockResolvedValue({
            form: { columns: 1, schema: [liveField({ value: 'permanent' })] },
            statePatch: {},
        });

        const wrapper = render([
            liveField({ value: 'contract' }),
            field({ name: 'contract_end_date', label: 'Contract end date' }),
        ]);

        expect(wrapper.find('#contract_end_date').exists()).toBe(true);

        await wrapper.find('#employment_type').setValue('permanent');
        await settle(wrapper);

        expect(wrapper.find('#contract_end_date').exists()).toBe(false);
    });

    it('keeps what the user typed into other fields', async () => {
        postJson.mockResolvedValue({
            form: {
                columns: 1,
                schema: [
                    liveField({ value: 'contract' }),
                    // The server rebuilt this field from the record, which is
                    // older than what is on screen.
                    field({
                        name: 'other',
                        label: 'Other',
                        value: 'from the record',
                    }),
                ],
            },
            statePatch: {},
        });

        const wrapper = render([
            liveField(),
            field({ name: 'other', label: 'Other' }),
        ]);

        await wrapper.find('#other').setValue('Draft name');
        await wrapper.find('#employment_type').setValue('contract');
        await settle(wrapper);

        // A rebuild describes what the form should look like, not what it
        // holds. Losing the draft here is the failure this guards.
        expect((wrapper.find('#other').element as HTMLInputElement).value).toBe(
            'Draft name',
        );
    });
});

/*
 * T19 — silence is part of the contract
 */

describe('a field that is not live', () => {
    it('asks the server nothing', async () => {
        const wrapper = render([
            field(),
            field({ name: 'other', label: 'Other' }),
        ]);

        await wrapper.find('#name').setValue('Apollo');
        await settle(wrapper);

        expect(postJson).not.toHaveBeenCalled();
    });
});

/*
 * T20 / PART P — fail closed
 */

describe('when the rebuild cannot happen', () => {
    it('makes no request when the form was given no state URL', async () => {
        const wrapper = render([liveField()], { formStateUrl: null });

        await wrapper.find('#employment_type').setValue('contract');
        await settle(wrapper);

        // A form rendered outside a resource has no endpoint to ask. Its live
        // fields behave as ordinary ones rather than erroring.
        expect(postJson).not.toHaveBeenCalled();
    });

    it('leaves the form exactly as it was when the request fails', async () => {
        // `postJson` answers null for anything that did not work.
        postJson.mockResolvedValue(null);

        const wrapper = render([
            liveField(),
            field({ name: 'other', label: 'Other' }),
        ]);

        await wrapper.find('#other').setValue('Draft name');
        await wrapper.find('#employment_type').setValue('contract');
        await settle(wrapper);

        expect(postJson).toHaveBeenCalledTimes(1);
        // Enrichment that could not be delivered must not cost the user what
        // they have entered.
        expect((wrapper.find('#other').element as HTMLInputElement).value).toBe(
            'Draft name',
        );
        expect(
            (wrapper.find('#employment_type').element as HTMLInputElement)
                .value,
        ).toBe('contract');
    });

    it('ignores an answer that is not a form', async () => {
        postJson.mockResolvedValue({ nonsense: true });

        const wrapper = render([liveField()]);

        await wrapper.find('#employment_type').setValue('contract');
        await settle(wrapper);

        expect(
            (wrapper.find('#employment_type').element as HTMLInputElement)
                .value,
        ).toBe('contract');
    });
});

/*
 * T18 — sequential changes
 */

describe('two live changes in a row', () => {
    it('applies the answer to the second, not the first', async () => {
        postJson
            .mockResolvedValueOnce({
                form: {
                    columns: 1,
                    schema: [
                        liveField({ value: 'contract' }),
                        field({ name: 'first_answer', label: 'First answer' }),
                    ],
                },
                statePatch: {},
            })
            .mockResolvedValueOnce({
                form: {
                    columns: 1,
                    schema: [
                        liveField({ value: 'permanent' }),
                        field({
                            name: 'second_answer',
                            label: 'Second answer',
                        }),
                    ],
                },
                statePatch: {},
            });

        const wrapper = render([liveField()]);

        await wrapper.find('#employment_type').setValue('contract');
        await settle(wrapper);

        await wrapper.find('#employment_type').setValue('permanent');
        await settle(wrapper);

        expect(postJson).toHaveBeenCalledTimes(2);
        expect(wrapper.find('#second_answer').exists()).toBe(true);
        expect(wrapper.find('#first_answer').exists()).toBe(false);
    });
});
