/**
 * @vitest-environment happy-dom
 */
import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { afterEach, describe, expect, it, vi } from 'vitest';

/**
 * The primitives U18 and B02/B03 changed, at the level a DOM can see.
 *
 * The sizes themselves are measurements and belong in the browser suite —
 * `size-11` is a string here and 44 pixels only in a layout engine. What is
 * asserted here is that behaviour and semantics survived being made bigger,
 * and that the motion policy is scoped rather than global.
 */
vi.mock('@inertiajs/vue3', () => ({
    router: {
        visit: vi.fn(),
        post: vi.fn(),
        reload: vi.fn(),
        on: () => () => {},
    },
    usePage: () => ({
        props: { translations: { ui: { close: 'Close', loading: 'Loading' } } },
        url: '/',
        component: '',
        version: null,
    }),
    Link: { name: 'Link', template: '<a><slot /></a>' },
}));

const { Switch } = await import('@/components/ui/switch');

const mounted: Array<{ unmount: () => void }> = [];

afterEach(() => {
    while (mounted.length > 0) {
        mounted.pop()?.unmount();
    }

    document.body.innerHTML = '';
});

/*
 * U18-T1 / U18-T2
 */

describe('the switch, now that the button is the target', () => {
    it('keeps its role and its state', async () => {
        const wrapper = mount(Switch, { attachTo: document.body });

        mounted.push(wrapper);

        const control = wrapper.get('[data-slot="switch"]');

        expect(control.attributes('role')).toBe('switch');
        expect(control.attributes('data-state')).toBe('unchecked');

        await control.trigger('click');

        expect(control.attributes('data-state')).toBe('checked');
    });

    it('still draws a track and a thumb inside it', () => {
        const wrapper = mount(Switch, { attachTo: document.body });

        mounted.push(wrapper);

        // The visible switch is unchanged; only the box around it grew.
        const track = wrapper.get('[data-slot="switch-track"]');

        expect(track.classes().join(' ')).toContain('w-8');
        expect(track.find('[data-slot="switch-thumb"]').exists()).toBe(true);
    });

    it('leaves the track out of the pointer path', () => {
        const wrapper = mount(Switch, { attachTo: document.body });

        mounted.push(wrapper);

        // Every tap must land on the button, including one inside the track.
        expect(wrapper.get('[data-slot="switch-track"]').classes()).toContain(
            'pointer-events-none',
        );
    });
});

/*
 * B03-T1 — the policy is scoped
 */

const STYLESHEET = readFileSync('resources/css/panda-panel.css', 'utf8');

describe('the reduced-motion policy', () => {
    it('exists at all', () => {
        expect(STYLESHEET).toContain('@media (prefers-reduced-motion: reduce)');
    });

    it('is scoped to what the package owns', () => {
        const block = STYLESHEET.slice(
            STYLESHEET.indexOf('@media (prefers-reduced-motion: reduce)'),
        );

        // The stylesheet is published *into* an application, so a bare `*`
        // here would reach markup the package does not own.
        expect(block).not.toMatch(/^\s*\*\s*[,{]/m);
        expect(block).toContain('[data-slot]');
        expect(block).toContain("[class*='panel-']");
    });

    it('exempts the indicators whose motion is the message', () => {
        const block = STYLESHEET.slice(
            STYLESHEET.indexOf('@media (prefers-reduced-motion: reduce)'),
        );

        expect(block).toContain(":not([data-slot='spinner'])");
        expect(block).toContain(":not([data-slot='skeleton'])");
    });

    it('does not use a zero duration', () => {
        const block = STYLESHEET.slice(
            STYLESHEET.indexOf('@media (prefers-reduced-motion: reduce)'),
        );

        // A zero-length animation never fires `animationend`, and a component
        // waiting for one would wait forever.
        expect(block).toContain('0.01ms');
        expect(block).not.toMatch(/animation-duration:\s*0s/);
    });
});

/*
 * B02-T1 / B02-T2
 */

describe('editor content typography', () => {
    it('is defined by the package, scoped to a package class', () => {
        expect(STYLESHEET).toContain('.panel-prose');
        // Never a bare element selector: this stylesheet is the host's too.
        expect(STYLESHEET).not.toMatch(/^\s*h2\s*\{/m);
    });

    it('is what the rich editor styles its content with', () => {
        const source = readFileSync(
            'resources/js/panel/forms/fields/RichEditorField.vue',
            'utf8',
        );

        expect(source).toContain('panel-prose');
        // The classes that resolved to nothing are gone.
        expect(source).not.toContain('prose-sm');
        expect(source).not.toContain('dark:prose-invert');
    });

    it('is not how the Markdown preview is styled, and the tokens still are', () => {
        // `md-editor-v3` brings its own content theme and exposes it as custom
        // properties, so that preview is themed by mapping those properties to
        // the same tokens rather than by winning a specificity contest with
        // them. What must not happen is the preview quietly keeping the
        // library's literal hex colours — a white pane and a `#2d8cf0` link in
        // a panel that is neither.
        const source = readFileSync(
            'resources/js/panel/forms/fields/MarkdownEditorField.vue',
            'utf8',
        );

        expect(source).toContain('--md-theme-link-color: var(--primary)');
        expect(source).toContain('--md-theme-bg-color: var(--background)');
        expect(source).toContain('--md-color: var(--foreground)');
        expect(source).not.toContain('prose-sm');
    });

    it('needs no dark-mode twin, because it uses tokens', () => {
        const block = STYLESHEET.slice(STYLESHEET.indexOf('.panel-prose'));

        expect(block).toContain('var(--foreground)');
        expect(block).toContain('var(--muted-foreground)');
        expect(block).not.toContain('.dark .panel-prose');
    });
});
