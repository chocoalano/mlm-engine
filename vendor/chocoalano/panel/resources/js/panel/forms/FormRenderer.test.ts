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

describe('submitting', () => {
    it('sends what was typed to the URL the server named', async () => {
        const wrapper = render([
            field(),
            field({ name: 'reason', label: 'Reason' }),
        ]);

        await wrapper.find('#name').setValue('After');
        await wrapper.find('#reason').setValue('Because');
        await wrapper.find('form').trigger('submit');

        expect(visit.mock.calls[0][0]).toBe(
            '/panel/relations/action-form?scope=record&action=rename',
        );
        expect(visitData()).toMatchObject({ name: 'After', reason: 'Because' });
    });

    it('carries the context the dialog was given alongside the values', async () => {
        const wrapper = render([field()], { context: { records: [1, 2] } });

        await wrapper.find('#name').setValue('Renamed');
        await wrapper.find('form').trigger('submit');

        // A bulk selection is the one thing the submit URL cannot carry, so it
        // rides here — kept apart from the values so a field cannot collide
        // with it.
        expect(visitData()).toMatchObject({
            name: 'Renamed',
            records: [1, 2],
        });
    });

    it('does not submit when a required field is empty', async () => {
        const wrapper = render([
            field({ required: true, validation: { required: true } }),
        ]);

        await wrapper.find('form').trigger('submit');

        // Checked here only to save a round trip; the server validates again.
        expect(visit).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('required');
    });
});

/*
 * T13 — a refusal has to leave the dialog usable
 */

describe('when the server refuses', () => {
    it('keeps what was typed and shows the error on the field', async () => {
        const wrapper = render([
            field(),
            field({ name: 'reason', label: 'Reason' }),
        ]);

        await wrapper.find('#name').setValue('Kept');
        await wrapper.find('form').trigger('submit');

        visitOptions().onError?.({ reason: 'The reason field is required.' });
        await wrapper.vm.$nextTick();

        // The dialog stays open because nothing told it to close, the value
        // survives because it is local, and the message lands on the field
        // that caused it.
        expect((wrapper.find('#name').element as HTMLInputElement).value).toBe(
            'Kept',
        );
        expect(wrapper.text()).toContain('The reason field is required.');
    });

    it('does not report the form as saved', async () => {
        const wrapper = render([field()]);

        await wrapper.find('form').trigger('submit');

        visitOptions().onError?.({ name: 'Nope' });
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('saved')).toBeUndefined();
    });

    it('clears a field error as soon as it is edited', async () => {
        const wrapper = render([field()]);

        await wrapper.find('form').trigger('submit');

        visitOptions().onError?.({ name: 'Nope' });
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Nope');

        await wrapper.find('#name').setValue('Fixed');

        // A corrected field stops looking wrong before the next round trip.
        expect(wrapper.text()).not.toContain('Nope');
    });
});

/*
 * T14 — success
 */

describe('when the action succeeds', () => {
    it('reports saved so the dialog can close itself', async () => {
        const wrapper = render([field()]);

        await wrapper.find('#name').setValue('After');
        await wrapper.find('form').trigger('submit');

        visitOptions().onSuccess?.();
        await wrapper.vm.$nextTick();

        // A page ignores this — the server has already redirected it — and a
        // dialog uses it. That is the whole difference between the two.
        expect(wrapper.emitted('saved')).toHaveLength(1);
    });
});

/*
 * T15 — the upload URL a file field is pointed at
 */

describe('file fields', () => {
    it('reaches its file field with the relation-aware upload URL', () => {
        let seen: string | null = null;

        // Stands in for the real control at the depth the real one sits, so
        // what is asserted is the injection actually arriving through the
        // layouts rather than the helper working in isolation.
        const Probe = {
            name: 'FileUploadField',
            props: ['field', 'modelValue', 'error'],
            setup() {
                seen = useUploadUrl()();

                return () => null;
            },
        };

        render(
            [fileField()],
            {
                uploadUrl:
                    '/panel/uploads?resource=projects&relation=tasks&action=rename&scope=record',
            },
            { FileUploadField: Probe },
        );

        // A file field on a relation action's form must upload as the action,
        // not as the resource: an action the user may not run must not be a
        // way to put a file on a disk.
        expect(seen).toContain('relation=tasks');
        expect(seen).toContain('action=rename');
        expect(seen).toContain('scope=record');
    });

    it('carries a stored file reference through to the submit', async () => {
        // What the upload endpoint answered with, put back as an ordinary
        // form value — which is the whole of the contract between the two
        // requests.
        const wrapper = render(
            [fileField({ value: 'evidence/note.txt' })],
            {},
            {
                FileUploadField: {
                    name: 'FileUploadField',
                    props: ['field', 'modelValue', 'error'],
                    template: '<div />',
                },
            },
        );

        await wrapper.find('form').trigger('submit');

        expect(visitData()).toMatchObject({ evidence: 'evidence/note.txt' });
    });
});
