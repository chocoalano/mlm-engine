/**
 * @vitest-environment happy-dom
 */
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import type { NavigationItem } from '@/panel/types/navigation';

/**
 * The header shell's navigation, and the cluster rail beside the content.
 *
 * The header rendered `item.href` and stopped. Navigation metadata carries
 * `children`, the sidebar draws them, and the header dropped them — so a panel
 * using the header layout had destinations its own navigation declared and no
 * way to reach. Not hidden by permission; never drawn.
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
            panel: { id: 'admin', path: 'admin', prefetch: false },
            translations: {
                shell: {
                    current_section: 'Current section',
                    panel_navigation: 'Panel navigation',
                },
            },
        },
        url: '/',
        component: '',
        version: null,
    }),
    Link: {
        name: 'Link',
        props: ['href', 'prefetch'],
        template: '<a :href="href"><slot /></a>',
    },
}));

const { default: PanelHeaderNavigation } =
    await import('@/panel/components/PanelHeaderNavigation.vue');

const mounted: Array<{ unmount: () => void }> = [];

afterEach(() => {
    while (mounted.length > 0) {
        mounted.pop()?.unmount();
    }

    document.body.innerHTML = '';
});

function item(overrides: Partial<NavigationItem> = {}): NavigationItem {
    return {
        label: 'Reports',
        href: '/admin/reports',
        icon: null,
        activeIcon: null,
        badge: null,
        active: false,
        fullPage: false,
        children: [],
        ...overrides,
    } as NavigationItem;
}

function render(navItem: NavigationItem) {
    const wrapper = mount(PanelHeaderNavigation, {
        attachTo: document.body,
        props: { item: navItem },
    });

    mounted.push(wrapper);

    return wrapper;
}

/** Menu content is teleported out of the wrapper. */
function menu() {
    return document.body;
}

/*
 * T5 / T6 / T7 / T8 / T9
 */

describe('a header item with no children', () => {
    it('is an ordinary link', () => {
        const wrapper = render(item());

        const link = wrapper.get('a');

        expect(link.attributes('href')).toBe('/admin/reports');
        // No menu: opening one onto a single destination is a press for
        // nothing.
        expect(wrapper.find('[aria-haspopup]').exists()).toBe(false);
    });

    it('marks itself as the current page when it is', () => {
        const wrapper = render(item({ active: true }));

        expect(wrapper.get('a').attributes('aria-current')).toBe('page');
    });

    it('leaves the SPA for a full-page destination', () => {
        const wrapper = render(item({ fullPage: true }));

        // A plain anchor, never prefetched — the rule the sidebar follows.
        expect(wrapper.get('a').element.tagName).toBe('A');
        expect(wrapper.find('a').attributes('prefetch')).toBeUndefined();
    });
});

describe('a header item with children', () => {
    const parent = item({
        label: 'People',
        href: '/admin/people',
        children: [
            item({ label: 'Employees', href: '/admin/people/employees' }),
            item({ label: 'Contractors', href: '/admin/people/contractors' }),
        ],
    });

    it('exposes every child destination', async () => {
        const wrapper = render(parent);

        await wrapper.get('[aria-haspopup="menu"]').trigger('click');
        await flushPromises();

        const links = [...menu().querySelectorAll('a')].map((a) =>
            a.getAttribute('href'),
        );

        expect(links).toContain('/admin/people/employees');
        expect(links).toContain('/admin/people/contractors');
    });

    it('keeps the parent its own destination too', async () => {
        const wrapper = render(parent);

        await wrapper.get('[aria-haspopup="menu"]').trigger('click');
        await flushPromises();

        // A parent has an href *and* children; choosing between them would
        // lose one of the two.
        const links = [...menu().querySelectorAll('a')].map((a) =>
            a.getAttribute('href'),
        );

        expect(links).toContain('/admin/people');
    });

    it('renders only the children it was given', async () => {
        const wrapper = render(
            item({
                label: 'People',
                href: '/admin/people',
                children: [
                    item({
                        label: 'Employees',
                        href: '/admin/people/employees',
                    }),
                ],
            }),
        );

        await wrapper.get('[aria-haspopup="menu"]').trigger('click');
        await flushPromises();

        // Permission filtering happens on the server. Nothing here invents a
        // destination or asks for a fuller list.
        const links = [...menu().querySelectorAll('a')].map((a) =>
            a.getAttribute('href'),
        );

        expect(links).toEqual(['/admin/people', '/admin/people/employees']);
    });

    it('says which section is current when a child is active', async () => {
        const wrapper = render(
            item({
                label: 'People',
                href: '/admin/people',
                active: false,
                children: [
                    item({
                        label: 'Employees',
                        href: '/admin/people/employees',
                        active: true,
                    }),
                ],
            }),
        );

        // The parent link is not the current page — the child is — so the
        // trigger says so in words rather than only in a background colour.
        expect(wrapper.get('[aria-haspopup="menu"]').text()).toContain(
            'Current section',
        );

        await wrapper.get('[aria-haspopup="menu"]').trigger('click');
        await flushPromises();

        const current = [...menu().querySelectorAll('[aria-current="page"]')];

        expect(current).toHaveLength(1);
        expect(current[0].getAttribute('href')).toBe('/admin/people/employees');
    });

    it('opens and closes from the keyboard through the primitive', async () => {
        const wrapper = render(parent);

        const trigger = wrapper.get('[aria-haspopup="menu"]');

        expect(trigger.attributes('aria-expanded')).toBe('false');

        await trigger.trigger('keydown', { key: 'Enter' });
        await flushPromises();

        expect(trigger.attributes('aria-expanded')).toBe('true');

        await trigger.trigger('keydown', { key: 'Escape' });
        await flushPromises();

        expect(trigger.attributes('aria-expanded')).toBe('false');
    });
});

/*
 * T1 / T2 / T3 / T4 — the cluster rail's responsive shape
 *
 * happy-dom resolves no Tailwind breakpoint, so what a runtime test can prove
 * is that both representations name the same destinations. Which one is
 * visible at a given width is asserted against the source, and is NOT BROWSER
 * VERIFIED.
 */

const CLUSTER = readFileSync(
    'resources/js/panel/components/PanelClusterBar.vue',
    'utf8',
);

describe('the cluster rail', () => {
    it('no longer reserves a fixed width at every size', () => {
        // `w-56 shrink-0` unconditionally leaves a 320px screen about ninety
        // pixels for the page the rail is meant to navigate.
        expect(CLUSTER).not.toMatch(/'flex w-56 shrink-0 flex-col gap-1'/);
        expect(CLUSTER).toContain('lg:w-56');
        expect(CLUSTER).toContain('lg:shrink-0');
    });

    it('wraps into a row below the breakpoint', () => {
        expect(CLUSTER).toContain('flex flex-wrap items-center');
        expect(CLUSTER).toContain('lg:flex-col');
    });

    it('puts the navigation above the content it navigates', () => {
        // The rail is markup-after-content so it can sit on the right; stacked,
        // that would put the navigation below the page.
        expect(CLUSTER).toContain('order-first');
        expect(CLUSTER).toContain('lg:order-none');
    });
});
