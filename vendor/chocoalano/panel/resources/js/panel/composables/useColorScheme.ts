import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import type { ComputedRef } from 'vue';

export type ColorScheme = 'light' | 'dark';

/**
 * Whether the panel is currently dark, as the *stylesheet* understands it.
 *
 * `useAppearance()` already answers "what did the user pick", and that is the
 * right answer for a control that writes the preference. It is the wrong
 * answer for a third-party editor that has to be handed a theme name, for two
 * reasons: the preference may be `system`, and the class on `<html>` is what
 * every other pixel in the panel is actually following. A host application is
 * free to toggle that class itself — a starter kit's own theme switch, a class
 * rendered by the server on first paint — and an editor reading the stored
 * preference would then be the one element on the page in the wrong theme.
 *
 * So this reads the class, and watches it. Tailwind's dark variant *is* a
 * class on the document element, which makes the class the single source of
 * truth and a `MutationObserver` the only way to follow it: nothing about a
 * `classList` mutation is reactive on its own.
 *
 * Safe where there is no document. Light is the right assumption for markup a
 * browser is about to re-theme on mount anyway.
 */
export function useColorScheme(): ComputedRef<ColorScheme> {
    const dark = ref(isDark());

    let observer: MutationObserver | null = null;

    onMounted(() => {
        dark.value = isDark();

        // `attributeFilter` rather than the whole subtree: the panel's theme
        // is one attribute on one element, and observing more would wake this
        // up for every class any page changes anywhere.
        observer = new MutationObserver(() => {
            dark.value = isDark();
        });

        observer.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class'],
        });
    });

    onBeforeUnmount(() => {
        observer?.disconnect();
        observer = null;
    });

    return computed<ColorScheme>(() => (dark.value ? 'dark' : 'light'));
}

function isDark(): boolean {
    return (
        typeof document !== 'undefined' &&
        document.documentElement.classList.contains('dark')
    );
}
