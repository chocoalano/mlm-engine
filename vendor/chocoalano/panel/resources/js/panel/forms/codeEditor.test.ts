/**
 * @vitest-environment happy-dom
 */
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import type { CodeEditorFieldDefinition } from '@/panel/types/form';

/**
 * What a code field is when Monaco is not there, and what it asks for when it
 * is.
 *
 * Monaco itself is not exercised here and should not be: it is several
 * megabytes of editor that needs a layout engine, and a `happy-dom` mount of it
 * would assert nothing true. What *is* asserted is the seam either side of it —
 * the fallback the field renders until the chunk arrives, and the options it
 * hands over once it has — because both of those are this package's code and
 * both of them were introduced with the rewrite.
 *
 * The fallback matters more than it looks. It is the control on any deployment
 * where that chunk cannot load, and a form that cannot be filled in is worse
 * than a form with a plain box in it.
 */
vi.mock('@inertiajs/vue3', () => ({
    router: {
        visit: vi.fn(),
        post: vi.fn(),
        reload: vi.fn(),
        on: () => () => {},
    },
    usePage: () => ({
        props: { translations: { forms: { plain_text: 'Plain text' } } },
        url: '/',
        component: '',
        version: null,
    }),
    Link: { name: 'Link', template: '<a><slot /></a>' },
}));

/** A stand-in for the editor, so the props it is given can be read. */
vi.mock('@guolao/vue-monaco-editor', () => ({
    VueMonacoEditor: {
        name: 'VueMonacoEditor',
        props: ['value', 'language', 'theme', 'options', 'width', 'height'],
        template: '<div data-testid="monaco" />',
    },
    loader: { config: vi.fn() },
}));

const configureMonaco = vi.fn();

vi.mock('@/panel/forms/monacoBundle', () => ({
    get configureMonaco() {
        return configureMonaco;
    },
}));

const { default: CodeEditorField } =
    await import('@/panel/forms/fields/CodeEditorField.vue');

const mounted: Array<{ unmount: () => void }> = [];

afterEach(() => {
    while (mounted.length > 0) {
        mounted.pop()?.unmount();
    }

    configureMonaco.mockReset();
    document.body.innerHTML = '';
});

function field(
    overrides: Record<string, unknown> = {},
): CodeEditorFieldDefinition {
    return {
        component: 'field',
        name: 'settings',
        label: 'Settings',
        type: 'code_editor',
        value: '{}',
        placeholder: null,
        helperText: 'Stored as written.',
        required: false,
        disabled: false,
        inlineLabel: false,
        columnSpan: 'full',
        conditions: { visibleWhen: [], hiddenWhen: [] },
        live: null,
        validation: { required: false },
        language: 'json',
        rows: 12,
        maxLength: null,
        ...overrides,
    } as unknown as CodeEditorFieldDefinition;
}

function render(props: Record<string, unknown> = {}) {
    const wrapper = mount(CodeEditorField, {
        attachTo: document.body,
        props: { field: field(), modelValue: '{}', ...props },
    });

    mounted.push(wrapper);

    return wrapper;
}

/** The editor arrives through a dynamic `import()`, so the promise queue has
 * to drain before the render that swaps the textarea out. */
async function settle(): Promise<void> {
    await flushPromises();
    await nextTick();
}

describe('before the editor has loaded', () => {
    it('is a textarea carrying the whole field wiring', async () => {
        const wrapper = render({ error: 'It is not valid JSON.' });

        const textarea = wrapper.get('textarea');
        const described = (textarea.attributes('aria-describedby') ?? '').split(
            ' ',
        );

        expect(described).toHaveLength(2);
        expect(textarea.attributes('aria-invalid')).toBe('true');
        expect(wrapper.get('label').attributes('for')).toBe(
            textarea.attributes('id'),
        );
        expect(document.getElementById(described[1])?.textContent?.trim()).toBe(
            'It is not valid JSON.',
        );
    });

    it('still edits the value', async () => {
        const wrapper = render();

        await wrapper.get('textarea').setValue('{"a": 1}');

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([
            '{"a": 1}',
        ]);
    });

    it('stays the control when the chunk cannot be loaded at all', async () => {
        configureMonaco.mockImplementation(() => {
            throw new Error('offline');
        });

        const error = vi.spyOn(console, 'error').mockImplementation(() => {});
        const wrapper = render();

        await settle();

        expect(wrapper.find('[data-testid="monaco"]').exists()).toBe(false);
        expect(wrapper.find('textarea').exists()).toBe(true);
        expect(error).toHaveBeenCalled();

        error.mockRestore();
    });
});

describe('once the editor has loaded', () => {
    it('replaces the textarea with it', async () => {
        const wrapper = render();

        await settle();

        expect(configureMonaco).toHaveBeenCalled();
        expect(wrapper.find('[data-testid="monaco"]').exists()).toBe(true);
        expect(wrapper.find('textarea').exists()).toBe(false);
    });

    it('asks for the grammar the enum names, not the enum case', async () => {
        const wrapper = render({ field: field({ language: 'plain' }) });

        await settle();

        // `plain` is this package's word and `plaintext` is Monaco's. An
        // unregistered id is not an error — it is an editor that silently
        // highlights nothing.
        expect(
            wrapper.getComponent({ name: 'VueMonacoEditor' }).props('language'),
        ).toBe('plaintext');
    });

    it('is read-only rather than absent when the field is disabled', async () => {
        const wrapper = render({ field: field({ disabled: true }) });

        await settle();

        expect(
            wrapper.getComponent({ name: 'VueMonacoEditor' }).props('options'),
        ).toMatchObject({ readOnly: true, minimap: { enabled: false } });
    });
});

describe('the header strip', () => {
    it('names the language and counts the lines it is given', () => {
        const wrapper = render({ modelValue: '{\n  "a": 1\n}' });

        expect(wrapper.text()).toContain('JSON');
        expect(wrapper.text()).toContain('3 lines');
    });
});
