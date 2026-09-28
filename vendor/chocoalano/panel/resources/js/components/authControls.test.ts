/**
 * @vitest-environment happy-dom
 */
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';

/**
 * Three controls a keyboard has to be able to operate.
 *
 * The appearance choice showed which option was selected only as a background
 * colour; the password toggle was removed from the tab order outright; and the
 * resend countdown never counted. Each is small, and each takes a working
 * feature away from somebody.
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
                auth: {
                    wait_before_retry:
                        'Wait :seconds seconds before asking again',
                    send_another_code: 'Send another code',
                    check_email: 'Check your email',
                    email_code_description: 'Sent to :email',
                    sign_in_code: 'Sign-in code',
                    code: 'Code',
                    continue: 'Continue',
                },
                ui: {
                    appearance: 'Appearance',
                    appearance_light: 'Light',
                    appearance_dark: 'Dark',
                    appearance_system: 'System',
                    show_password: 'Show password',
                    hide_password: 'Hide password',
                },
            },
        },
        url: '/',
        component: '',
        version: null,
    }),
    Link: { name: 'Link', template: '<a><slot /></a>' },
    Form: {
        name: 'Form',
        template: '<form><slot :errors="{}" :processing="false" /></form>',
    },
    Head: { name: 'Head', template: '<div />' },
}));

let matches = false;
const listeners: Array<(event: { matches: boolean }) => void> = [];

beforeEach(() => {
    matches = false;
    listeners.length = 0;
    vi.resetModules();

    vi.stubGlobal('matchMedia', () => ({
        matches,
        addEventListener: (
            _: string,
            listener: (event: { matches: boolean }) => void,
        ) => void listeners.push(listener),
        removeEventListener: () => {},
    }));

    const store = new Map<string, string>();

    vi.stubGlobal('localStorage', {
        getItem: (key: string) => store.get(key) ?? null,
        setItem: (key: string, value: string) => void store.set(key, value),
        removeItem: (key: string) => void store.delete(key),
        clear: () => store.clear(),
        key: () => null,
        length: 0,
    });
});

afterEach(() => {
    document.body.innerHTML = '';
});

/*
 * U05 — O / P / Q
 */

describe('choosing an appearance', () => {
    it('says which one is selected', async () => {
        const { default: AppearanceTabs } =
            await import('@/components/AppearanceTabs.vue');

        const wrapper = mount(AppearanceTabs, { attachTo: document.body });

        const group = wrapper.get('[role="radiogroup"]');
        const options = wrapper.findAll('[role="radio"]');

        // Three plain buttons before this, with the selection expressed only
        // as a background colour: nothing in the markup said which was chosen.
        expect(group.attributes('aria-label')).toBe('Appearance');
        expect(options).toHaveLength(3);

        const checked = options.filter(
            (option) => option.attributes('aria-checked') === 'true',
        );

        expect(checked).toHaveLength(1);
        expect(checked[0].text()).toContain('System');
    });

    it('can be changed from the keyboard', async () => {
        const { default: AppearanceTabs } =
            await import('@/components/AppearanceTabs.vue');

        const wrapper = mount(AppearanceTabs, { attachTo: document.body });

        const options = wrapper.findAll('[role="radio"]');

        (options[2].element as HTMLElement).focus();
        await nextTick();

        await options[2].trigger('keydown', { key: 'ArrowLeft' });

        // The primitive moves focus on the arrow key and checks the newly
        // focused radio from a timeout, so the selection lands a macrotask
        // later rather than on the next render.
        await new Promise((resolve) => setTimeout(resolve, 0));
        await nextTick();

        expect(options[1].attributes('aria-checked')).toBe('true');
        expect(options[2].attributes('aria-checked')).toBe('false');
    });

    it('still follows the operating system on System', async () => {
        const { default: AppearanceTabs } =
            await import('@/components/AppearanceTabs.vue');
        const { useAppearance, initializeTheme } =
            await import('@/composables/useAppearance');

        mount(AppearanceTabs, { attachTo: document.body });
        initializeTheme();

        const { resolvedAppearance } = useAppearance();

        expect(resolvedAppearance.value).toBe('light');

        // The UI-1 fix, asserted from the component that sets the choice: a
        // radio group must not have brought its own copy of the theme state.
        matches = true;
        listeners.forEach((listener) => listener({ matches: true }));
        await nextTick();

        expect(resolvedAppearance.value).toBe('dark');
    });
});

/*
 * U06 — R / S / T
 */

describe('showing a password', () => {
    it('is reachable from the keyboard', async () => {
        const { default: PasswordInput } =
            await import('@/components/PasswordInput.vue');

        const wrapper = mount(PasswordInput, { attachTo: document.body });

        const toggle = wrapper.get('button');

        // It carried `tabindex="-1"`, which took a feature away from exactly
        // the people most likely to want it.
        expect(toggle.attributes('tabindex')).toBeUndefined();
        expect(toggle.attributes('type')).toBe('button');
    });

    it('says what it does and what state it is in', async () => {
        const { default: PasswordInput } =
            await import('@/components/PasswordInput.vue');

        const wrapper = mount(PasswordInput, { attachTo: document.body });

        const toggle = wrapper.get('button');

        expect(toggle.attributes('aria-label')).toBe('Show password');
        expect(toggle.attributes('aria-pressed')).toBe('false');

        await toggle.trigger('click');

        expect(toggle.attributes('aria-label')).toBe('Hide password');
        expect(toggle.attributes('aria-pressed')).toBe('true');
    });

    it('keeps what was typed when it is toggled', async () => {
        const { default: PasswordInput } =
            await import('@/components/PasswordInput.vue');

        const wrapper = mount(PasswordInput, {
            attachTo: document.body,
            props: { modelValue: 'correct horse' },
        });

        const input = wrapper.get('input');

        expect(input.attributes('type')).toBe('password');

        await wrapper.get('button').trigger('click');

        expect(wrapper.get('input').attributes('type')).toBe('text');
        expect((wrapper.get('input').element as HTMLInputElement).value).toBe(
            'correct horse',
        );
    });
});

/*
 * U07, in the page that carries it
 */

describe('the resend button on the code page', () => {
    it('counts the wait down instead of freezing on it', async () => {
        vi.useFakeTimers();

        const { default: EmailCode } =
            await import('@/pages/panel/auth/EmailCode.vue');

        const wrapper = mount(EmailCode, {
            attachTo: document.body,
            props: {
                panel: {
                    path: 'admin',
                    brandName: 'Admin',
                    theme: { light: {}, dark: {} },
                } as never,
                sentTo: 'a***@example.test',
                retryAfter: 3,
            },
        });

        const resend = wrapper.findAll('button').at(-1);

        // The number was the one the server measured at render time and it
        // never moved: "wait 3 seconds", disabled, for as long as the page
        // stayed open.
        expect(resend?.attributes('disabled')).toBeDefined();
        expect(resend?.text()).toContain('3');

        vi.advanceTimersByTime(3000);
        await nextTick();

        expect(resend?.attributes('disabled')).toBeUndefined();

        vi.useRealTimers();
    });
});
