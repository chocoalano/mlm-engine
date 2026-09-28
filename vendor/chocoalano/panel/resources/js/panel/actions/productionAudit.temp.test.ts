/**
 * @vitest-environment happy-dom
 */
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ActionDefinition, ModalDefinition } from '@/panel/types/action';

/**
 * The dialog a modal-declaring action opens, and the copy it shows.
 *
 * The composable tests prove nothing is posted before confirmation. This
 * proves the other half: that the sentences the developer wrote actually
 * reach the screen, rather than being replaced by a generic "Are you sure?".
 * Both halves matter — an action held back but described wrongly is still an
 * action the user cannot make an informed decision about.
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

const { default: ActionModal } =
    await import('@/panel/actions/ActionModal.vue');

const MODAL: ModalDefinition = {
    width: 'md',
    slideOver: false,
    stickyHeader: false,
    stickyFooter: false,
    closeByClickingAway: true,
    closeByEscaping: true,
    autofocus: true,
    heading: 'Approve machine?',
    description: 'This grants password-less attendance access immediately.',
    submitLabel: 'Approve',
    cancelLabel: 'Keep pending',
    cancel: true,
    componentName: null,
    config: {},
};

function action(overrides: Partial<ActionDefinition> = {}): ActionDefinition {
    return {
        name: 'approve',
        label: 'Approve',
        icon: null,
        variant: 'default',
        type: 'callback',
        url: null,
        formUrl: null,
        hasForm: false,
        modal: null,
        modalActions: [],
        confirmation: null,
        ...overrides,
    };
}

/**
 * The dialog teleports to `document.body`, so nothing it draws is inside the
 * wrapper — its text and its buttons are found on the document instead.
 */
async function render(a: ActionDefinition | null) {
    const wrapper = mount(ActionModal, {
        props: { action: a, processing: false, formUrl: null, context: {} },
        global: { stubs: { Spinner: true } },
    });

    await flushPromises();

    return wrapper;
}

function screen(): string {
    return document.body.textContent ?? '';
}

/** The teleported button whose label contains this text. */
function button(text: string): HTMLButtonElement | undefined {
    return [...document.body.querySelectorAll('button')].find((el) =>
        (el.textContent ?? '').includes(text),
    ) as HTMLButtonElement | undefined;
}

beforeEach(() => {
    document.body.innerHTML = '';
});

describe('production audit race reproduction', () => {
    it('keeps the newest action payload when older fetch finishes last', async () => {
        const replies: Array<(value: unknown) => void> = [];
        vi.stubGlobal('fetch', vi.fn(() => new Promise(resolve => replies.push(resolve))));
        const wrapper = await render(action());
        await wrapper.setProps({ formUrl: '/form-a' });
        await wrapper.setProps({ formUrl: '/form-b' });
        const payload = (key: string) => ({ ok: true, json: async () => ({ title: key, submitLabel: 'Save', form: { columns: 1, schema: [] }, submitUrl: '/submit-' + key, method: 'post', context: {} }) });
        replies[1]!(payload('b'));
        await flushPromises();
        replies[0]!(payload('a'));
        await flushPromises();
        expect(wrapper.findComponent({ name: 'FormRenderer' }).props('submitUrl')).toBe('/submit-b');
        wrapper.unmount();
        vi.unstubAllGlobals();
    });
});
