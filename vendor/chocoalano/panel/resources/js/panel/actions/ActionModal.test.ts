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

describe('a non-form action that declared modal copy', () => {
    it('shows the heading the developer wrote', async () => {
        await render(action({ modal: MODAL }));

        expect(screen()).toContain('Approve machine?');
    });

    it('shows the description the developer wrote', async () => {
        await render(action({ modal: MODAL }));

        // Not replaced by a generic confirmation sentence.
        expect(screen()).toContain(
            'This grants password-less attendance access immediately.',
        );
        expect(screen()).not.toContain('Are you sure');
    });

    it('labels its buttons from the declaration', async () => {
        await render(action({ modal: MODAL }));

        expect(screen()).toContain('Approve');
        expect(screen()).toContain('Keep pending');
    });

    it('asks to run only when the confirm button is pressed', async () => {
        const wrapper = await render(action({ modal: MODAL }));

        // Opening it is not running it.
        expect(wrapper.emitted('confirm')).toBeUndefined();

        button('Approve')?.click();
        await flushPromises();

        expect(wrapper.emitted('confirm')).toHaveLength(1);
    });

    it('cancels without asking to run', async () => {
        const wrapper = await render(action({ modal: MODAL }));

        button('Keep pending')?.click();
        await flushPromises();

        expect(wrapper.emitted('cancel')).toHaveLength(1);
        expect(wrapper.emitted('confirm')).toBeUndefined();
    });

    it('renders nothing at all when no action is pending', async () => {
        await render(null);

        expect(screen()).not.toContain('Approve machine?');
    });
});

describe('an action that declared a confirmation', () => {
    it('still shows its own copy', async () => {
        await render(
            action({
                confirmation: {
                    heading: 'Delete device?',
                    description: 'This cannot be undone.',
                    button: 'Delete it',
                },
            }),
        );

        expect(screen()).toContain('Delete device?');
        expect(screen()).toContain('This cannot be undone.');
        expect(screen()).toContain('Delete it');
    });
});
