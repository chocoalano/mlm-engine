/**
 * @vitest-environment happy-dom
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick } from 'vue';

/**
 * The resolved appearance, when the operating system changes its mind.
 *
 * `system` is the default, so this is the common case rather than an edge one.
 * The stylesheet followed along — a listener toggles the `dark` class — but
 * `resolvedAppearance` was computed from an imperative `matchMedia` read,
 * which nothing invalidates. Everything downstream of it (the logo, the panel
 * palette, chart colours) kept the value it had when the computed first ran.
 * The page went dark and the branding stayed light.
 */
type Listener = (event: { matches: boolean }) => void;

let matches = false;
const listeners: Listener[] = [];

function setSystemDark(value: boolean): void {
    matches = value;

    for (const listener of listeners) {
        listener({ matches: value });
    }
}

beforeEach(() => {
    matches = false;
    listeners.length = 0;
    document.documentElement.classList.remove('dark');

    // happy-dom does not expose one globally here, and the module reads it
    // directly. A map is enough: what is under test is the media query.
    const store = new Map<string, string>();

    vi.stubGlobal('localStorage', {
        getItem: (k: string) => store.get(k) ?? null,
        setItem: (k: string, v: string) => void store.set(k, v),
        removeItem: (k: string) => void store.delete(k),
        clear: () => store.clear(),
        key: () => null,
        length: 0,
    });

    vi.stubGlobal('matchMedia', (query: string) => ({
        matches,
        media: query,
        addEventListener: (_: string, listener: Listener) => {
            listeners.push(listener);
        },
        removeEventListener: () => {},
        addListener: () => {},
        removeListener: () => {},
        onchange: null,
        dispatchEvent: () => false,
    }));
});

describe('system appearance', () => {
    it('resolves whatever the OS currently prefers', async () => {
        const { useAppearance, initializeTheme } =
            await import('@/composables/useAppearance');

        initializeTheme();

        const { resolvedAppearance } = useAppearance();

        expect(resolvedAppearance.value).toBe('light');
    });

    it('updates reactively when the OS changes while the page is open', async () => {
        vi.resetModules();

        const { useAppearance, initializeTheme } =
            await import('@/composables/useAppearance');

        initializeTheme();

        const { resolvedAppearance } = useAppearance();

        expect(resolvedAppearance.value).toBe('light');

        setSystemDark(true);
        await nextTick();

        // The finding: this stayed 'light', so the class went dark and every
        // consumer of the resolved value did not.
        expect(resolvedAppearance.value).toBe('dark');
        expect(document.documentElement.classList.contains('dark')).toBe(true);
    });

    it('goes back when the OS goes back', async () => {
        vi.resetModules();

        const { useAppearance, initializeTheme } =
            await import('@/composables/useAppearance');

        initializeTheme();

        const { resolvedAppearance } = useAppearance();

        setSystemDark(true);
        await nextTick();
        setSystemDark(false);
        await nextTick();

        expect(resolvedAppearance.value).toBe('light');
    });

    it('ignores the OS once an explicit choice is made', async () => {
        vi.resetModules();

        const { useAppearance, initializeTheme } =
            await import('@/composables/useAppearance');

        initializeTheme();

        const { resolvedAppearance, updateAppearance } = useAppearance();

        updateAppearance('light');

        setSystemDark(true);
        await nextTick();

        // An explicit preference is a preference, not a starting point.
        expect(resolvedAppearance.value).toBe('light');
    });
});

describe('a host with no usable storage', () => {
    it('resolves an appearance instead of throwing', async () => {
        // A browser set to block site data throws on the property access, not
        // on the call. Nothing here is exotic: the composable is used by every
        // component that styles itself, so one throw during mount takes the
        // whole surface down.
        vi.stubGlobal('localStorage', {
            get getItem(): never {
                throw new DOMException('denied', 'SecurityError');
            },
        });

        const { useAppearance } = await import('@/composables/useAppearance');

        const scope = effectScope();
        let resolved = '';

        scope.run(() => {
            resolved = useAppearance().resolvedAppearance.value;
        });

        expect(resolved).toBe('light');

        scope.stop();
    });
});
