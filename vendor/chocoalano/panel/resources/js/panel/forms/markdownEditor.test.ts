/**
 * @vitest-environment happy-dom
 */
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import type { MarkdownEditorFieldDefinition } from '@/panel/types/form';

/**
 * What the schema asks for, and what the editor is asked for.
 *
 * `md-editor-v3` has its own vocabulary — `strikeThrough`, `unorderedList`,
 * `quote` — and `MarkdownEditor::toolbar()` has the vocabulary it has always
 * had. The translation between them is this package's code and it is the one
 * thing here that can silently drop a button: an unrecognised name renders
 * nothing, exactly as before, so a mistake in the map looks like a schema that
 * asked for a button it never had.
 *
 * The editor itself is stubbed. Mounting CodeMirror in `happy-dom` would
 * assert a layout engine's behaviour without one; what the *field* decides is
 * the props it hands over, and those are readable from a stub.
 */
vi.mock('@inertiajs/vue3', () => ({
    router: {
        visit: vi.fn(),
        post: vi.fn(),
        reload: vi.fn(),
        on: () => () => {},
    },
    usePage: () => ({
        props: { translations: {} },
        url: '/',
        component: '',
        version: null,
    }),
    Link: { name: 'Link', template: '<a><slot /></a>' },
}));

vi.mock('md-editor-v3', () => ({
    MdEditor: {
        name: 'MdEditor',
        props: [
            'modelValue',
            'toolbars',
            'footers',
            'theme',
            'language',
            'disabled',
            'placeholder',
            'maxLength',
            'tabWidth',
            'noHighlight',
            'noPrettier',
            'noMermaid',
            'noKatex',
            'noEcharts',
            'noUploadImg',
            'noImgZoomIn',
        ],
        emits: ['update:modelValue'],
        // The element CodeMirror would create, so the field's own accessibility
        // wiring has something to find.
        template:
            '<div><div class="cm-content" contenteditable="true" /></div>',
    },
}));

vi.mock('md-editor-v3/lib/style.css', () => ({}));

const { default: MarkdownEditorField } =
    await import('@/panel/forms/fields/MarkdownEditorField.vue');

const mounted: Array<{ unmount: () => void }> = [];

afterEach(() => {
    while (mounted.length > 0) {
        mounted.pop()?.unmount();
    }

    document.documentElement.classList.remove('dark');
    document.body.innerHTML = '';
});

function field(
    overrides: Record<string, unknown> = {},
): MarkdownEditorFieldDefinition {
    return {
        component: 'field',
        name: 'body',
        label: 'Body',
        type: 'markdown_editor',
        value: '# Title',
        placeholder: null,
        helperText: 'Markdown is stored as written.',
        required: false,
        disabled: false,
        inlineLabel: false,
        columnSpan: 'full',
        conditions: { visibleWhen: [], hiddenWhen: [] },
        live: null,
        validation: { required: false },
        toolbar: [
            'bold',
            'italic',
            'strike',
            'link',
            'heading',
            'bulletList',
            'orderedList',
            'blockquote',
            'code',
            'preview',
        ],
        maxLength: null,
        rows: 10,
        ...overrides,
    } as unknown as MarkdownEditorFieldDefinition;
}

function render(props: Record<string, unknown> = {}) {
    const wrapper = mount(MarkdownEditorField, {
        attachTo: document.body,
        props: { field: field(), modelValue: '# Title', ...props },
    });

    mounted.push(wrapper);

    return wrapper;
}

function editor(wrapper: ReturnType<typeof render>) {
    return wrapper.getComponent({ name: 'MdEditor' });
}

describe('the toolbar the schema asked for', () => {
    it('is translated into the editor’s own names, in order', () => {
        expect(editor(render()).props('toolbars')).toEqual([
            'bold',
            'italic',
            'strikeThrough',
            'link',
            'title',
            'unorderedList',
            'orderedList',
            'quote',
            'codeRow',
            'preview',
        ]);
    });

    it('drops a name the editor does not have, rather than passing it through', () => {
        // An unknown name reaching the editor is not a missing button — it is
        // an exception, because the prop is a union.
        const wrapper = render({
            field: field({ toolbar: ['bold', 'nonsense', 'italic'] }),
        });

        expect(editor(wrapper).props('toolbars')).toEqual(['bold', 'italic']);
    });

    it('renders no toolbar at all for an empty list', () => {
        const wrapper = render({ field: field({ toolbar: [] }) });

        expect(editor(wrapper).props('toolbars')).toEqual([]);
    });
});

describe('what the field refuses to let the editor do', () => {
    it('turns off every feature that would fetch code from a CDN', () => {
        // The panel has to work behind a proxy, under a CSP that names its own
        // origins, and on an air-gapped install.
        expect(editor(render()).props()).toMatchObject({
            noHighlight: true,
            noPrettier: true,
            noMermaid: true,
            noKatex: true,
            noEcharts: true,
            noUploadImg: true,
        });
    });
});

describe('the field wiring', () => {
    it('lands on the element CodeMirror gives the keyboard to', async () => {
        const wrapper = render({ error: 'It is required.' });

        await nextTick();

        const content = wrapper.get('.cm-content');
        const described = (content.attributes('aria-describedby') ?? '').split(
            ' ',
        );

        expect(content.attributes('id')).toBe('body');
        expect(content.attributes('aria-label')).toBe('Body');
        expect(content.attributes('aria-invalid')).toBe('true');
        expect(described).toHaveLength(2);
        expect(wrapper.get('label').attributes('for')).toBe('body');
    });

    it('follows the panel’s theme rather than a stored preference', async () => {
        const wrapper = render();

        expect(editor(wrapper).props('theme')).toBe('light');

        document.documentElement.classList.add('dark');
        await nextTick();
        await nextTick();

        expect(editor(wrapper).props('theme')).toBe('dark');
    });

    it('passes what was typed straight back out', async () => {
        const wrapper = render();

        editor(wrapper).vm.$emit('update:modelValue', '## Changed');

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([
            '## Changed',
        ]);
    });
});
