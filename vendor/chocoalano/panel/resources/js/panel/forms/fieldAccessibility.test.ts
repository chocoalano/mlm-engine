/**
 * @vitest-environment happy-dom
 */
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type {
    FieldDefinition,
    FormComponentDefinition,
} from '@/panel/types/form';

/**
 * Whether a label, a helper, an error and a control are one thing.
 *
 * All four were rendered and none were connected: the label pointed at
 * `field.name`, the helper and the error had no ids at all, and a control
 * could say it was invalid without being able to say what about it was.
 * Somebody using a screen reader heard the label and then nothing.
 *
 * These mount the real components rather than calling the id helper, because
 * the helper being right was never the question — what mattered is whether
 * the attributes reach the DOM.
 */
const visit = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    router: {
        visit: (...args: unknown[]) => visit(...args),
        post: vi.fn(),
        reload: vi.fn(),
        on: () => () => {},
    },
    usePage: () => ({
        props: {
            translations: {
                forms: {
                    error_summary: 'This form has :count error(s).',
                    tab_has_errors: 'This tab has errors',
                },
            },
        },
        url: '/',
        component: '',
        version: null,
    }),
    Link: { name: 'Link', template: '<a><slot /></a>' },
}));

const { fetchState } = vi.hoisted(() => ({
    /** `(url, values, name, previous, signal)` — the third is the field. */
    fetchState: vi.fn<(...args: unknown[]) => Promise<null>>(async () => null),
}));

vi.mock('@/panel/forms/formStateEndpoint', async (original) => ({
    ...(await original<Record<string, unknown>>()),
    fetchFormState: (...args: unknown[]) => fetchState(...args),
}));

const { default: FormRenderer } =
    await import('@/panel/forms/FormRenderer.vue');

function field(overrides: Record<string, unknown> = {}): FieldDefinition {
    return {
        component: 'field',
        name: 'title',
        label: 'Title',
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

/**
 * Mounted into the document, because `aria-describedby` is resolved by id
 * across the whole document and a detached wrapper cannot answer that. Torn
 * down between tests for the same reason: two live forms would both hold a
 * `title-error` and `getElementById` would answer with whichever came first.
 */
const mounted: Array<{ unmount: () => void }> = [];

afterEach(() => {
    while (mounted.length > 0) {
        mounted.pop()?.unmount();
    }

    document.body.innerHTML = '';
});

function render(
    schema: FormComponentDefinition[],
    props: Record<string, unknown> = {},
) {
    const wrapper = mount(FormRenderer, {
        attachTo: document.body,
        props: {
            form: { schema, columns: 1 },
            submitUrl: '/panel/things',
            ...props,
        },
    });

    mounted.push(wrapper);

    return wrapper;
}

/*
 * U01 — A / B / C / D
 */

describe('a field and the words around it', () => {
    it('points the label at the control', () => {
        const wrapper = render([field()]);

        const input = wrapper.get('input');
        const label = wrapper.get('label');

        expect(label.attributes('for')).toBe(input.attributes('id'));
    });

    it('names its helper in aria-describedby', () => {
        const wrapper = render([field({ helperText: 'Shown in the list.' })]);

        const described = wrapper.get('input').attributes('aria-describedby');

        expect(described).toBeDefined();

        const helper = document.getElementById(described as string);

        expect(helper?.textContent?.trim()).toBe('Shown in the list.');
    });

    it('names its error in aria-describedby', async () => {
        const wrapper = render([
            field({ required: true, validation: { required: true } }),
        ]);

        await wrapper.get('form').trigger('submit');

        const described = wrapper.get('input').attributes('aria-describedby');
        const ids = (described ?? '').split(' ');

        expect(ids).toHaveLength(1);
        expect(document.getElementById(ids[0])?.textContent?.trim()).not.toBe(
            '',
        );
    });

    it('names both when there is a helper and an error', async () => {
        const wrapper = render([
            field({
                helperText: 'Shown in the list.',
                required: true,
                validation: { required: true },
            }),
        ]);

        await wrapper.get('form').trigger('submit');

        const ids = (
            wrapper.get('input').attributes('aria-describedby') ?? ''
        ).split(' ');

        // Both, and the helper first: an error explains the refusal, and the
        // helper still explains what the field wants. Dropping the helper the
        // moment a field goes red takes the instructions away exactly when
        // they are most needed.
        expect(ids).toHaveLength(2);
        expect(document.getElementById(ids[0])?.textContent?.trim()).toBe(
            'Shown in the list.',
        );
        expect(document.getElementById(ids[1])?.getAttribute('role')).toBe(
            'alert',
        );
    });

    it('says it is invalid only once it is', async () => {
        const wrapper = render([
            field({ required: true, validation: { required: true } }),
        ]);

        // Not `aria-invalid="false"` on a field nobody has submitted yet:
        // every field on the form would be announcing a state it is not in.
        expect(wrapper.get('input').attributes('aria-invalid')).toBeUndefined();

        await wrapper.get('form').trigger('submit');

        expect(wrapper.get('input').attributes('aria-invalid')).toBe('true');
    });
});

/*
 * U02 — E / F / G
 */

function repeater(overrides: Record<string, unknown> = {}): FieldDefinition {
    return field({
        name: 'contacts',
        label: 'Contacts',
        type: 'repeater',
        value: [{ name: 'Ada' }, { name: 'Grace' }],
        schema: [field({ name: 'name', label: 'Name' })],
        itemLabels: [],
        emptyItem: { name: '' },
        addable: true,
        deletable: true,
        reorderable: true,
        collapsible: false,
        addLabel: 'Add',
        minItems: null,
        maxItems: null,
        ...overrides,
    });
}

describe('a field repeated in a repeater', () => {
    it('gives each entry its own control id', () => {
        const wrapper = render([repeater()]);

        const ids = wrapper
            .findAll('input')
            .map((input) => input.attributes('id'));

        // Two entries of the same sub-schema. Both were `id="name"`.
        expect(ids).toHaveLength(2);
        expect(ids[0]).not.toBe(ids[1]);
        expect(new Set(ids).size).toBe(2);
    });

    it('points each label at its own entry', () => {
        const wrapper = render([repeater()]);

        const inputs = wrapper.findAll('input');
        // The repeater's own wrapper renders a label too, so the entry labels
        // are the ones after it.
        const labels = wrapper
            .findAll('label')
            .filter((label) => label.attributes('for') !== undefined);

        const entryLabels = labels.slice(-2);

        expect(entryLabels[0].attributes('for')).toBe(
            inputs[0].attributes('id'),
        );
        expect(entryLabels[1].attributes('for')).toBe(
            inputs[1].attributes('id'),
        );
        // The whole defect: clicking the second entry's label focused the
        // first entry's input, because both were `for="name"`.
        expect(entryLabels[0].attributes('for')).not.toBe(
            entryLabels[1].attributes('for'),
        );
    });

    it('keeps helper and error ids unique per entry', () => {
        const wrapper = render([
            repeater({
                schema: [
                    field({
                        name: 'name',
                        label: 'Name',
                        helperText: 'Full name.',
                    }),
                ],
            }),
        ]);

        const described = wrapper
            .findAll('input')
            .map((input) => input.attributes('aria-describedby'));

        expect(described[0]).toBeDefined();
        expect(described[0]).not.toBe(described[1]);
    });

    it('leaves the submitted paths alone', async () => {
        const wrapper = render([repeater()]);

        await wrapper.findAll('input')[1].setValue('Grace H.');

        const payload = visit.mock.calls.at(-1);

        expect(payload).toBeUndefined();

        await wrapper.get('form').trigger('submit');

        // What crosses the wire is unchanged: the DOM id is a rendering
        // detail and the state path is the contract. A fix that renamed the
        // payload to make the ids unique would have broken every server-side
        // rule that names `contacts.*.name`.
        const [, options] = visit.mock.calls.at(-1) as [
            string,
            { data: Record<string, unknown> },
        ];

        expect(options.data.contacts).toEqual([
            { name: 'Ada' },
            { name: 'Grace H.' },
        ]);
    });

    it("keeps an entry's id when it is moved", async () => {
        const wrapper = render([repeater()]);

        const before = wrapper
            .findAll('input')
            .map((input) => input.attributes('id'));

        // "Move down" on the first entry. Identity follows the entry, not the
        // slot it happens to be sitting in — otherwise reordering renumbers
        // every control below the move and changes ids nobody touched.
        const down = wrapper
            .findAll('button')
            .filter((button) =>
                button.attributes('aria-label')?.includes('down'),
            );

        await down[0].trigger('click');
        await wrapper.vm.$nextTick();

        const after = wrapper
            .findAll('input')
            .map((input) => input.attributes('id'));

        expect(after).toEqual([before[1], before[0]]);
    });
});

/*
 * U03 — H / I / J
 */

function tabs(
    schema: FormComponentDefinition[][],
    keys: string[],
    fieldNames: string[][],
): FormComponentDefinition {
    return {
        component: 'tabs',
        persistTab: false,
        tabs: keys.map((key, index) => ({
            key,
            label: key,
            icon: null,
            badge: null,
            fields: fieldNames[index],
            schema: schema[index],
        })),
    } as unknown as FormComponentDefinition;
}

describe('a submit the server refused', () => {
    it('moves focus to the first invalid field', async () => {
        const wrapper = render([
            field({ name: 'title', label: 'Title' }),
            field({
                name: 'slug',
                label: 'Slug',
                required: true,
                validation: { required: true },
            }),
        ]);

        await wrapper.get('form').trigger('submit');
        await flushPromises();

        // Not the first field, and not wherever the button left focus: the
        // first field the form actually refused.
        expect((document.activeElement as HTMLElement)?.id).toBe('slug');
    });

    it('chooses the first in rendered order, not the first key', async () => {
        const wrapper = render([
            field({
                name: 'alpha',
                label: 'Alpha',
                required: true,
                validation: { required: true },
            }),
            field({
                name: 'beta',
                label: 'Beta',
                required: true,
                validation: { required: true },
            }),
        ]);

        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect((document.activeElement as HTMLElement)?.id).toBe('alpha');
    });

    it('opens the tab holding the error before focusing into it', async () => {
        const wrapper = render([
            tabs(
                [
                    [field({ name: 'title', label: 'Title' })],
                    [
                        field({
                            name: 'slug',
                            label: 'Slug',
                            required: true,
                            validation: { required: true },
                        }),
                    ],
                ],
                ['general', 'seo'],
                [['title'], ['slug']],
            ),
        ]);

        // The second tab is closed, and a field inside a closed tab cannot
        // take focus at all — silently. This is the case that made a rejected
        // submit look like a submit that did nothing.
        expect(wrapper.get('[data-state="active"]').text()).toContain(
            'general',
        );

        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('[data-state="active"]').text()).toContain('seo');
        expect((document.activeElement as HTMLElement)?.id).toBe('slug');
    });

    it('says how many, and links to each', async () => {
        const wrapper = render([
            field({
                name: 'title',
                label: 'Title',
                required: true,
                validation: { required: true },
            }),
            field({
                name: 'slug',
                label: 'Slug',
                required: true,
                validation: { required: true },
            }),
        ]);

        await wrapper.get('form').trigger('submit');

        const summary = wrapper.get('[role="alert"]');

        expect(summary.text()).toContain('2');
        // The labels, not the messages: the messages are already on the
        // fields, and repeating them here is a second wall of text to read
        // before reaching the first one.
        expect(summary.text()).toContain('Title');
        expect(summary.text()).toContain('Slug');
    });
});

/*
 * The identity is a rendering detail, not the contract
 */

describe('a field whose name is not a legal id', () => {
    it('renders a usable id and keeps the state path', async () => {
        // A relation group names its fields `profile.bio`, and a dot in an id
        // is legal HTML but breaks every CSS selector that touches it.
        const wrapper = render([field({ name: 'profile.bio', label: 'Bio' })]);

        const input = wrapper.get('input');

        expect(input.attributes('id')).toBe('profile-bio');
        expect(wrapper.get('label').attributes('for')).toBe('profile-bio');

        await input.setValue('Hello');
        await wrapper.get('form').trigger('submit');

        const [, options] = visit.mock.calls.at(-1) as [
            string,
            { data: Record<string, unknown> },
        ];

        // Expanded to nested data exactly as before: the id changed and the
        // payload did not.
        expect(options.data.profile).toEqual({ bio: 'Hello' });
    });

    it('still reaches the live endpoint on blur', async () => {
        const wrapper = render(
            [
                field({
                    name: 'profile.bio',
                    label: 'Bio',
                    live: { debounce: 0, onBlur: true },
                }),
            ],
            { formStateUrl: '/panel/form-state' },
        );

        const input = wrapper.get('input');

        await input.setValue('Hello');
        await input.trigger('focusout');

        // The form turns the blurred element back into a field through the
        // registry. Reading `target.id` worked only while the id and the state
        // path were the same string.
        expect(fetchState).toHaveBeenCalled();
        expect(fetchState.mock.calls[0][2]).toBe('profile.bio');
    });
});
