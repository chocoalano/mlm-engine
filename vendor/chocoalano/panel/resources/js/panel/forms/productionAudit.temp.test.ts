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
 * What a relation action's form actually sends, and what it does with the
 * answer.
 *
 * The composable decides *whether* a form opens; this decides what leaves the
 * browser once it has. Three things matter and none of them is visible from
 * the server: that the values typed reach the submit, that a refusal leaves
 * the dialog holding them, and that a file field is pointed at the
 * relation-aware upload URL rather than the resource's.
 */
const visit = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    router: {
        visit: (...args: unknown[]) => visit(...args),
        post: vi.fn(),
        reload: vi.fn(),
        on: () => () => {},
    },
    usePage: () => ({ props: {}, url: '/', component: '', version: null }),
    Link: { name: 'Link', template: '<a><slot /></a>' },
}));

const { default: FormRenderer } =
    await import('@/panel/forms/FormRenderer.vue');

const { useUploadUrl } = await import('@/panel/forms/uploadEndpoint');

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

/** A file field, for the upload contract. */
function fileField(overrides: Record<string, unknown> = {}): FieldDefinition {
    return field({
        name: 'evidence',
        label: 'Evidence',
        type: 'file_upload',
        value: null,
        multiple: false,
        maxSize: 1024,
        maxFiles: null,
        acceptedTypes: [],
        image: false,
        previewBase: null,
        ...overrides,
    });
}

function render(
    schema: FormComponentDefinition[],
    props: Record<string, unknown> = {},
    stubs: Record<string, unknown> = {},
) {
    return mount(FormRenderer, {
        props: {
            form: { columns: 1, schema },
            submitUrl:
                '/panel/relations/action-form?scope=record&action=rename',
            context: {},
            ...props,
        },
        global: { stubs: { Spinner: true, ...stubs } },
    });
}

/** The options object the router was handed, so callbacks can be invoked. */
function visitOptions(): Record<string, (payload?: unknown) => void> {
    expect(visit).toHaveBeenCalledTimes(1);

    return visit.mock.calls[0][1] as Record<string, (p?: unknown) => void>;
}

function visitData(): Record<string, unknown> {
    return (visit.mock.calls[0][1] as { data: Record<string, unknown> }).data;
}

beforeEach(() => {
    visit.mockClear();
});

/*
 * T12 — the values reach the request
 */

describe('production audit reproduction', () => {
    it('refreshes values when server returns a fresh form after create-another', async () => {
        const wrapper = render([field()]);
        await wrapper.get('input').setValue('Already saved');
        await wrapper.setProps({ form: { columns: 1, schema: [field({ value: '' })] } });
        expect((wrapper.get('input').element as HTMLInputElement).value).toBe('');
        wrapper.unmount();
    });
    it('refuses to submit an invalid optional manual date', async () => {
        const wrapper = render([field({ type: 'date', name: 'date', value: '2026-09-10', minDate: null, maxDate: null })]);
        await wrapper.get('input').setValue('2026-02-30');
        expect(wrapper.get('input').attributes('aria-invalid')).toBe('true');
        await wrapper.get('form').trigger('submit');
        expect(visit).not.toHaveBeenCalled();
        wrapper.unmount();
    });
    it('holds submission while a file is still uploading', async () => {
        vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {})));
        const wrapper = render([fileField()], { uploadUrl: '/upload' });
        const input = wrapper.get('input[type="file"]');
        Object.defineProperty(input.element, 'files', { value: [new File(['x'], 'a.txt')] });
        await input.trigger('change');
        await wrapper.get('form').trigger('submit');
        expect(visit).not.toHaveBeenCalled();
        wrapper.unmount();
        vi.unstubAllGlobals();
    });
});
