/**
 * @vitest-environment happy-dom
 */
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import type { RichEditorFieldDefinition } from '@/panel/types/form';

/**
 * The editor's semantics, as opposed to its appearance.
 *
 * What it *looks* like when focused is a browser question and is answered in
 * `tests/browser/ui6.mjs` — a computed box-shadow that is neither transparent
 * nor zero-width. What is asserted here is the half a DOM implementation can
 * see: that the editable region still says what it is, that the field wiring
 * from U01 survived the move to Tiptap, and that a toolbar toggle both reports
 * a state and changes the document.
 *
 * Every one of these was true of the `execCommand` version too, and that is
 * the point of keeping them: the control underneath changed completely and the
 * contract this form layer depends on did not.
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
                forms: {
                    editor_bullet_list: '• List',
                    editor_link: 'Link',
                },
            },
        },
        url: '/',
        component: '',
        version: null,
    }),
    Link: { name: 'Link', template: '<a><slot /></a>' },
}));

const { default: RichEditorField } =
    await import('@/panel/forms/fields/RichEditorField.vue');

const mounted: Array<{ unmount: () => void }> = [];

afterEach(() => {
    while (mounted.length > 0) {
        mounted.pop()?.unmount();
    }

    document.body.innerHTML = '';
});

function field(
    overrides: Record<string, unknown> = {},
): RichEditorFieldDefinition {
    return {
        component: 'field',
        name: 'body',
        label: 'Body',
        type: 'rich_editor',
        value: '<p>Content.</p>',
        placeholder: null,
        helperText: 'Formatting applies to the selection.',
        required: false,
        disabled: false,
        inlineLabel: false,
        columnSpan: 'full',
        conditions: { visibleWhen: [], hiddenWhen: [] },
        live: null,
        validation: { required: false },
        toolbar: ['bold', 'italic', 'bulletList', 'link', 'undo'],
        maxLength: null,
        ...overrides,
    } as unknown as RichEditorFieldDefinition;
}

/**
 * Mounted and then waited on, because Tiptap builds its view in `onMounted`
 * and the `contenteditable` does not exist until it has.
 */
async function render(props: Record<string, unknown> = {}) {
    const wrapper = mount(RichEditorField, {
        attachTo: document.body,
        props: { field: field(), modelValue: '<p>Content.</p>', ...props },
    });

    mounted.push(wrapper);

    // Twice: once for the editor to be constructed, once for the render that
    // reads it. Tiptap's reactivity is debounced behind two animation frames,
    // which is why the state assertions below drive it explicitly instead.
    await nextTick();
    await nextTick();

    return wrapper;
}

/*
 * U17-T1 / U17-T2
 */

describe('the editable region', () => {
    it('still says it is a multi-line textbox', async () => {
        const wrapper = await render();

        const editor = wrapper.get('[contenteditable="true"]');

        expect(editor.attributes('role')).toBe('textbox');
        expect(editor.attributes('aria-multiline')).toBe('true');
    });

    it('keeps the field wiring U01 established', async () => {
        const wrapper = await render({
            field: field({ helperText: 'Some help.' }),
            error: 'It is required.',
        });

        const editor = wrapper.get('[contenteditable="true"]');
        const described = (editor.attributes('aria-describedby') ?? '').split(
            ' ',
        );

        // Both the helper and the error, and the invalid state — the same
        // contract every other field carries. These live on an element
        // ProseMirror creates, so they arrive through `editorProps.attributes`
        // rather than through the template, and that is exactly why they are
        // asserted.
        expect(described).toHaveLength(2);
        expect(editor.attributes('aria-invalid')).toBe('true');
        expect(document.getElementById(described[0])?.textContent?.trim()).toBe(
            'Some help.',
        );
        expect(document.getElementById(described[1])?.textContent?.trim()).toBe(
            'It is required.',
        );
        expect(wrapper.get('label').attributes('for')).toBe(
            editor.attributes('id'),
        );
    });

    it('marks the field boundary rather than removing the outline and stopping', async () => {
        const wrapper = await render();

        // The class list is the part a DOM implementation can see; whether
        // anything is painted is asserted in the browser suite.
        const shell = wrapper.get('[data-slot="rich-editor"]');

        expect(shell.classes().join(' ')).toContain('focus-within:border-ring');
        expect(
            wrapper.get('[contenteditable="true"]').classes().join(' '),
        ).toContain('focus-visible:ring-3');
    });

    it('renders the value it was given', async () => {
        const wrapper = await render({
            modelValue: '<p>Existing <strong>content</strong>.</p>',
        });

        expect(wrapper.get('[contenteditable="true"]').html()).toContain(
            '<strong>content</strong>',
        );
    });
});

/*
 * U17-T3 / U17-T4
 */

describe('a toolbar toggle', () => {
    it('reports a pressed state', async () => {
        const wrapper = await render();

        // Present and false, rather than absent: a toggle with no state is a
        // toggle nobody can read.
        expect(
            wrapper.get('[aria-label="bold"]').attributes('aria-pressed'),
        ).toBe('false');
    });

    it('claims no pressed state for a control that is not a toggle', async () => {
        const wrapper = await render();

        // Undo is an action, not a state. `aria-pressed` on it would say the
        // editor is "currently undone".
        expect(
            wrapper.get('[aria-label="undo"]').attributes('aria-pressed'),
        ).toBeUndefined();
    });

    it('changes the document rather than only the markup', async () => {
        const wrapper = await render();

        // A document-level assertion, not a string match: the whole reason for
        // the move to Tiptap is that there is now a document to ask.
        await wrapper.get('[aria-label="bulletList"]').trigger('click');

        const emitted = wrapper.emitted('update:modelValue') ?? [];

        expect(emitted.length).toBeGreaterThan(0);
        expect(String(emitted[emitted.length - 1][0])).toContain('<ul>');
    });

    it('emits nothing but the empty string for an emptied document', async () => {
        const wrapper = await render();

        await wrapper.setProps({ modelValue: '' });
        await nextTick();

        expect(wrapper.get('[contenteditable="true"]').text()).toBe('');
    });
});
