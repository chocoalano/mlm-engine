import type { ComputedRef, Ref } from 'vue';
import { computed, onMounted, ref } from 'vue';
import type { Appearance, ResolvedAppearance } from '@/types';

export type { Appearance, ResolvedAppearance };

export type UseAppearanceReturn = {
    appearance: Ref<Appearance>;
    resolvedAppearance: ComputedRef<ResolvedAppearance>;
    updateAppearance: (value: Appearance) => void;
};

export function updateTheme(value: Appearance): void {
    if (typeof window === 'undefined') {
        return;
    }

    if (value === 'system') {
        const mediaQueryList = window.matchMedia(
            '(prefers-color-scheme: dark)',
        );
        const systemTheme = mediaQueryList.matches ? 'dark' : 'light';

        document.documentElement.classList.toggle(
            'dark',
            systemTheme === 'dark',
        );
    } else {
        document.documentElement.classList.toggle('dark', value === 'dark');
    }
}

const setCookie = (name: string, value: string, days = 365) => {
    if (typeof document === 'undefined') {
        return;
    }

    const maxAge = days * 24 * 60 * 60;

    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
};

const mediaQuery = () => {
    if (typeof window === 'undefined') {
        return null;
    }

    return window.matchMedia('(prefers-color-scheme: dark)');
};

/**
 * The stored preference, or null when there is nowhere to have stored one.
 *
 * `typeof window` is not enough of a guard on its own. A browser set to block
 * site data throws on the property access itself, and a non-browser host may
 * define `window` without defining storage at all — in either case the theme
 * should fall back to what the system prefers rather than take the mount down
 * with it.
 */
const getStoredAppearance = (): Appearance | null => {
    try {
        return (
            (globalThis.localStorage?.getItem(
                'appearance',
            ) as Appearance | null) ?? null
        );
    } catch {
        return null;
    }
};

const storeAppearance = (value: Appearance): void => {
    try {
        globalThis.localStorage?.setItem('appearance', value);
    } catch {
        // The cookie below is the other half of this, and it is the half SSR
        // reads. Losing the local copy costs a redundant write, not the
        // preference.
    }
};

const prefersDark = (): boolean => {
    if (typeof window === 'undefined') {
        return false;
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches;
};

/**
 * What the operating system currently prefers, as reactive state.
 *
 * A ref rather than a `matchMedia` read inside a computed. Reading the media
 * query imperatively gives the right answer once and never again: nothing
 * tells Vue to re-evaluate when the OS changes, so on `system` the stylesheet
 * followed along — the class is toggled by the listener below — while
 * everything computed from the resolved appearance stayed on the old value.
 * The page went dark and the logo did not.
 *
 * One ref, written by the one listener, read by every consumer.
 */
const systemPrefersDark = ref(prefersDark());

const handleSystemThemeChange = () => {
    const currentAppearance = getStoredAppearance();

    systemPrefersDark.value = prefersDark();

    updateTheme(currentAppearance || 'system');
};

export function initializeTheme(): void {
    if (typeof window === 'undefined') {
        return;
    }

    // Initialize theme from saved preference or default to system...
    const savedAppearance = getStoredAppearance();
    updateTheme(savedAppearance || 'system');

    // Set up system theme change listener...
    systemPrefersDark.value = prefersDark();

    mediaQuery()?.addEventListener('change', handleSystemThemeChange);
}

const appearance = ref<Appearance>('system');

export function useAppearance(): UseAppearanceReturn {
    onMounted(() => {
        const savedAppearance = getStoredAppearance();

        if (savedAppearance) {
            appearance.value = savedAppearance;
        }

        // In case the OS changed between module evaluation and mount, or the
        // listener has not been installed by `initializeTheme()` yet.
        systemPrefersDark.value = prefersDark();
    });

    const resolvedAppearance = computed<ResolvedAppearance>(() => {
        if (appearance.value === 'system') {
            return systemPrefersDark.value ? 'dark' : 'light';
        }

        return appearance.value;
    });

    function updateAppearance(value: Appearance) {
        appearance.value = value;

        // Store in localStorage for client-side persistence...
        storeAppearance(value);

        // Store in cookie for SSR...
        setCookie('appearance', value);

        updateTheme(value);
    }

    return {
        appearance,
        resolvedAppearance,
        updateAppearance,
    };
}
