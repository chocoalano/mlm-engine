/**
 * @vitest-environment happy-dom
 */
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import type { TabsDefinition } from '@/panel/types/form';

/**
 * A tab set someone can actually operate from a keyboard.
 *
 * The old markup had the half of the tabs pattern that shows up in a DOM
 * inspector — `role="tab"`, `aria-selected`, `aria-controls` — and none of the
 * half that makes it work: arrow keys did nothing, every tab was a tab stop,
 * and the ids were global, so two tab sets on one page both called their panel
 * `panel-details`.
 *
 * These drive the rendered component with real key events rather than
 * asserting on the source, because what changed is behaviour that only exists
 * once the thing is mounted.
 */
vi.mock('@inertiajs/vue3', () => ({
    router: {
        visit: vi.fn(),
        post: vi.fn(),
        reload: vi.fn(),
        on: () => () => {},
    },
    usePage: () => ({ props: {}, url: '/', component: '', version: null }),
    Link: { name: 'Link', template: '<a><slot /></a>' },
}));

const { default: FormTabs } = await import('@/panel/forms/FormTabs.vue');

const mounted: Array<{ unmount: () => void }> = [];

afterEach(() => {
    while (mounted.length > 0) {
        mounted.pop()?.unmount();
    }

    document.body.innerHTML = '';
});

function definition(keys: string[]): TabsDefinition {
    return {
        component: 'tabs',
        persistTab: false,
        tabs: keys.map((key) => ({
            key,
            label: key,
            icon: null,
            badge: null,
            fields: [],
            schema: [],
        })),
    } as unknown as TabsDefinition;
}

function render(keys: string[] = ['one', 'two', 'three']) {
    const wrapper = mount(FormTabs, {
        attachTo: document.body,
        props: {
            tabs: definition(keys),
            values: {},
            errors: {},
        },
    });

    mounted.push(wrapper);

    return wrapper;
}

function triggers(wrapper: ReturnType<typeof render>) {
    return wrapper.findAll('[role="tab"]');
}

/**
 * Keyboard navigation starts from wherever focus is, so it has to be
 * somewhere. Tabbing into the set puts it on the selected tab; this is that,
 * without a browser.
 */
async function focus(tab: { element: Element }): Promise<void> {
    (tab.element as HTMLElement).focus();

    await nextTick();
}

/*
 * U04 — K / L / M / N
 */

describe('moving between tabs with the keyboard', () => {
    it('follows the arrow keys', async () => {
        const wrapper = render();
        const tabs = triggers(wrapper);

        await focus(tabs[0]);

        await tabs[0].trigger('keydown', { key: 'ArrowRight' });

        expect(tabs[1].attributes('data-state')).toBe('active');
        // Focus follows the selection, which is what makes the next arrow
        // press continue from here rather than starting over.
        expect(document.activeElement).toBe(tabs[1].element);

        await tabs[1].trigger('keydown', { key: 'ArrowLeft' });

        expect(tabs[0].attributes('data-state')).toBe('active');
    });

    it('jumps to the ends with Home and End', async () => {
        const wrapper = render();
        const tabs = triggers(wrapper);

        await focus(tabs[0]);

        await tabs[0].trigger('keydown', { key: 'End' });

        expect(tabs[2].attributes('data-state')).toBe('active');

        await tabs[2].trigger('keydown', { key: 'Home' });

        expect(tabs[0].attributes('data-state')).toBe('active');
    });

    it('is one tab stop, not one per tab', async () => {
        const wrapper = render();
        const tabs = triggers(wrapper);

        await focus(tabs[0]);

        // A roving tabindex. Without it, Tab walks through every tab in the
        // set before reaching the content, which on a six-tab form is five
        // presses of nothing happening.
        expect(tabs[0].attributes('tabindex')).toBe('0');
        expect(tabs[1].attributes('tabindex')).toBe('-1');
        expect(tabs[2].attributes('tabindex')).toBe('-1');
    });

    it('moves the tab stop with the selection', async () => {
        const wrapper = render();
        const tabs = triggers(wrapper);

        await focus(tabs[0]);

        await tabs[0].trigger('keydown', { key: 'ArrowRight' });

        expect(tabs[0].attributes('tabindex')).toBe('-1');
        expect(tabs[1].attributes('tabindex')).toBe('0');
    });
});

describe('two tab sets on one page', () => {
    it('gives them different ids', () => {
        // Both sets in one app, because that is the situation: ids are unique
        // per application, and mounting twice would be two applications and
        // therefore two counters starting from the same number.
        const wrapper = mount(
            {
                components: { FormTabs },
                setup: () => ({ tabs: definition(['details', 'seo']) }),
                template: `
                    <div>
                        <FormTabs :tabs="tabs" :values="{}" :errors="{}" />
                        <FormTabs :tabs="tabs" :values="{}" :errors="{}" />
                    </div>
                `,
            },
            { attachTo: document.body },
        );

        mounted.push(wrapper);

        const ids = wrapper
            .findAll('[role="tab"]')
            .map((tab) => tab.attributes('id'));

        // Four tabs, two of them keyed `details`. They used to both be
        // `tab-details`, which makes `aria-controls` ambiguous and a
        // `getElementById` answer arbitrary.
        expect(ids).toHaveLength(4);
        expect(ids.every((id) => id !== undefined)).toBe(true);
        expect(new Set(ids).size).toBe(4);
    });

    it('points each panel at its own tab', async () => {
        const wrapper = render(['details', 'seo']);

        // The panels register themselves on mount and the triggers point at
        // them once they have, so the pairing is complete a tick later.
        await nextTick();

        const tabs = triggers(wrapper);
        const panels = wrapper.findAll('[role="tabpanel"]');

        expect(panels[0].attributes('aria-labelledby')).toBe(
            tabs[0].attributes('id'),
        );
        expect(tabs[0].attributes('aria-controls')).toBe(
            panels[0].attributes('id'),
        );
    });
});

describe('a tab holding a rejected field', () => {
    it('says so in words, not only in colour', () => {
        const wrapper = mount(FormTabs, {
            attachTo: document.body,
            props: {
                tabs: {
                    component: 'tabs',
                    persistTab: false,
                    tabs: [
                        {
                            key: 'general',
                            label: 'general',
                            icon: null,
                            badge: null,
                            fields: ['title'],
                            schema: [],
                        },
                    ],
                } as unknown as TabsDefinition,
                values: {},
                errors: { title: 'Required.' },
            },
        });

        mounted.push(wrapper);

        // A red dot was the whole indicator. Anyone who cannot distinguish it
        // from the badge next to it had no way to tell which tab to open.
        expect(wrapper.get('.sr-only').text()).toBe('Tab has errors');
    });
});
