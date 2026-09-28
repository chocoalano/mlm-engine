/**
 * @vitest-environment happy-dom
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick, ref } from 'vue';

/**
 * How a panel's colours reach the screen.
 *
 * Three things were wrong and they compounded. The palette was resolved as
 * "light, always", so a panel that customised its colours kept its light ones
 * in dark mode. The resolved appearance was computed from a `matchMedia` read
 * rather than from reactive state, so on `system` the stylesheet followed the
 * OS and everything computed from it did not. And the whole palette lived on
 * the shell, which every overlay is teleported out of.
 *
 * These drive the composable rather than reading its source: the failures are
 * about *when* a value is recomputed and *where* it is written, and neither is
 * visible in the text of the file.
 */
const panelRef = ref<Record<string, unknown> | null>(null);
const resolvedRef = ref<'light' | 'dark'>('light');

vi.mock('@/panel/composables/usePanel', () => ({
    usePanel: () => ({ panel: panelRef }),
}));

vi.mock('@/composables/useAppearance', () => ({
    useAppearance: () => ({ resolvedAppearance: resolvedRef }),
}));

const { usePanelStyling } = await import('@/panel/composables/usePanelStyling');

const THEME = {
    light: { primary: '#4f46e5', sidebar: '#f8fafc' },
    dark: { primary: '#a5b4fc' },
};

/** Runs the composable inside a scope so its cleanup can be triggered. */
function mount(): {
    scope: ReturnType<typeof effectScope>;
    style: () => Record<string, string>;
} {
    const scope = effectScope();
    let api!: ReturnType<typeof usePanelStyling>;

    scope.run(() => {
        api = usePanelStyling();
    });

    return { scope, style: () => api.themeStyle.value };
}

function cssVar(name: string): string {
    return document.documentElement.style.getPropertyValue(name);
}

beforeEach(() => {
    document.documentElement.style.cssText = '';
    panelRef.value = { id: 'a', theme: THEME };
    resolvedRef.value = 'light';
});

/*
 * T01 — the palette follows the resolved appearance
 */

describe('resolving a panel palette', () => {
    it('uses the light palette when light is resolved', () => {
        const { style, scope } = mount();

        expect(style()['--primary']).toBe('#4f46e5');

        scope.stop();
    });

    it('uses the dark palette when dark is resolved', async () => {
        const { style, scope } = mount();

        resolvedRef.value = 'dark';
        await nextTick();

        // The whole finding: this used to stay '#4f46e5' because only
        // `theme.light` was ever read, and an inline custom property on the
        // shell outranks the stylesheet's `.dark` block.
        expect(style()['--primary']).toBe('#a5b4fc');

        scope.stop();
    });

    it('does not carry a light value into dark when dark omits it', async () => {
        const { style, scope } = mount();

        resolvedRef.value = 'dark';
        await nextTick();

        // `sidebar` is customised for light only. Reusing it here is how a
        // pale surface ends up in a dark panel; the package default should
        // win instead.
        expect(style()['--sidebar-background']).toBeUndefined();

        scope.stop();
    });

    it('writes nothing for a panel with no theme', () => {
        panelRef.value = { id: 'plain' };

        const { style, scope } = mount();

        expect(style()).toEqual({});

        scope.stop();
    });
});

/*
 * T03 — the config key lands on the token the utility reads
 */

describe('the sidebar token', () => {
    it('writes the canonical name the stylesheet resolves', () => {
        const { style, scope } = mount();

        // `bg-sidebar` resolves `--sidebar-background`. Writing `--sidebar`
        // set a variable nothing read.
        expect(style()['--sidebar-background']).toBe('#f8fafc');
        expect(style()['--sidebar']).toBeUndefined();

        scope.stop();
    });

    it('passes the canonical key straight through', () => {
        panelRef.value = {
            id: 'a',
            theme: { light: { 'sidebar-background': '#eef2ff' } },
        };

        const { style, scope } = mount();

        expect(style()['--sidebar-background']).toBe('#eef2ff');

        scope.stop();
    });
});

/*
 * T04 — the palette reaches what the shell does not contain
 */

describe('where the palette is written', () => {
    it('puts the tokens on the document element so portals inherit them', () => {
        const { scope } = mount();

        // Dialogs, sheets, popovers, selects, dropdowns, tooltips and toasts
        // are all teleported to `body`, which is a sibling of the shell — not
        // a descendant. The document element is the node both are inside.
        expect(cssVar('--primary')).toBe('#4f46e5');

        scope.stop();
    });

    it("replaces the previous panel's tokens rather than leaving them", async () => {
        const { scope } = mount();

        expect(cssVar('--sidebar-background')).toBe('#f8fafc');

        panelRef.value = { id: 'b', theme: { light: { primary: '#16a34a' } } };
        await nextTick();

        expect(cssVar('--primary')).toBe('#16a34a');
        // Panel B says nothing about the sidebar, so A's must be gone rather
        // than lingering on the next overlay that opens.
        expect(cssVar('--sidebar-background')).toBe('');

        scope.stop();
    });

    it('follows the resolved appearance on the document element too', async () => {
        const { scope } = mount();

        resolvedRef.value = 'dark';
        await nextTick();

        expect(cssVar('--primary')).toBe('#a5b4fc');

        scope.stop();
    });

    it('cleans up when the panel goes away', async () => {
        const { scope } = mount();

        expect(cssVar('--primary')).toBe('#4f46e5');

        scope.stop();
        await nextTick();

        // The package is published into a host application; leaving the
        // panel's accent on the document after leaving the panel would tint
        // whatever the host draws next.
        expect(cssVar('--primary')).toBe('');
    });
});
