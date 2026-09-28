/**
 * @vitest-environment happy-dom
 */
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * The command palette: what it says it is, and what it says went wrong.
 *
 * It already knew how to search — debounce, abort, an active index the arrow
 * keys move. What it could not do was describe any of that. The input was a
 * placeholder and nothing else; the results were links carrying `aria-selected`
 * without a role that gives the attribute meaning; and a refused request was
 * assigned `[]`, so a 500 and "nothing matches" were one screen.
 */
const searchSettings = {
    enabled: true,
    url: '/admin/search',
    debounce: 0,
    keyBindings: ['mod+k'],
};

vi.mock('@inertiajs/vue3', () => ({
    router: {
        visit: vi.fn(),
        post: vi.fn(),
        reload: vi.fn(),
        on: () => () => {},
    },
    usePage: () => ({
        props: {
            panel: { id: 'admin', path: 'admin' },
            search: searchSettings,
            translations: {
                shell: {
                    search: 'Search',
                    search_label: 'Search this panel',
                    search_results: 'Search results',
                    search_placeholder: 'Search...',
                    search_description: 'Search across this panel.',
                    search_too_short: 'Type at least two characters.',
                    search_empty: 'Nothing found.',
                    search_searching: 'Searching…',
                    search_result_count: ':count result(s)',
                    search_failed: 'The search could not be run.',
                    search_stale:
                        'Showing earlier results — the update failed.',
                    retry: 'Try again',
                },
            },
        },
        url: '/',
        component: '',
        version: null,
    }),
    Link: {
        name: 'Link',
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
}));

const { default: PanelSearch } =
    await import('@/panel/components/PanelSearch.vue');

const mounted: Array<{ unmount: () => void }> = [];

const fetchMock = vi.fn();

beforeEach(() => {
    fetchMock.mockReset();
    vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
    while (mounted.length > 0) {
        mounted.pop()?.unmount();
    }

    document.body.innerHTML = '';
});

function answer(titles: string[]) {
    return {
        ok: true,
        json: async () => ({
            groups:
                titles.length === 0
                    ? []
                    : [
                          {
                              resource: 'people',
                              label: 'People',
                              icon: null,
                              results: titles.map((title) => ({
                                  title,
                                  url: `/admin/people/${title}`,
                                  details: {},
                              })),
                          },
                      ],
        }),
    };
}

async function open(query = 'an') {
    const wrapper = mount(PanelSearch, { attachTo: document.body });

    mounted.push(wrapper);

    await wrapper.get('button').trigger('click');
    await flushPromises();

    const input = document.querySelector('input') as HTMLInputElement;

    input.value = query;
    input.dispatchEvent(new Event('input'));

    await flushPromises();
    await new Promise((resolve) => setTimeout(resolve, 5));
    await flushPromises();

    return { wrapper, input };
}

function options(): HTMLElement[] {
    return [...document.querySelectorAll<HTMLElement>('[role="option"]')];
}

function status(): string {
    return document.querySelector('[role="status"]')?.textContent?.trim() ?? '';
}

/*
 * T10 / T11 / T12 / T18
 */

describe('what the search input says it is', () => {
    it('carries a label of its own', async () => {
        fetchMock.mockResolvedValue(answer([]));

        const { input } = await open('');

        // A placeholder disappears the moment anything is typed, and is not
        // what assistive technology reads.
        expect(input.getAttribute('aria-label')).toBe('Search this panel');
        expect(input.getAttribute('role')).toBe('combobox');
    });

    it('controls the listbox that holds its results', async () => {
        fetchMock.mockResolvedValue(answer(['Ada', 'Grace']));

        const { input } = await open();

        const listboxId = input.getAttribute('aria-controls');

        expect(listboxId).toBeTruthy();

        const listbox = document.getElementById(listboxId as string);

        expect(listbox?.getAttribute('role')).toBe('listbox');
        expect(listbox?.querySelectorAll('[role="option"]')).toHaveLength(2);
        expect(input.getAttribute('aria-expanded')).toBe('true');
    });

    it('points aria-activedescendant at an option that exists', async () => {
        fetchMock.mockResolvedValue(answer(['Ada', 'Grace']));

        const { input } = await open();

        const activeId = input.getAttribute('aria-activedescendant');

        expect(activeId).toBeTruthy();
        // Naming an id nothing renders is worse than leaving it off.
        expect(document.getElementById(activeId as string)).not.toBeNull();
        expect(document.getElementById(activeId as string)).toBe(options()[0]);
    });

    it('names no active option when there are none', async () => {
        fetchMock.mockResolvedValue(answer([]));

        const { input } = await open();

        expect(input.getAttribute('aria-activedescendant')).toBeNull();
        expect(input.getAttribute('aria-expanded')).toBe('false');
    });

    it('gives two palettes on one page different ids', async () => {
        fetchMock.mockResolvedValue(answer(['Ada']));

        const first = mount(PanelSearch, { attachTo: document.body });
        const second = mount(PanelSearch, { attachTo: document.body });

        mounted.push(first, second);
        await flushPromises();

        const ids = [...document.querySelectorAll('[role="combobox"]')].map(
            (input) => input.getAttribute('aria-controls'),
        );

        expect(new Set(ids).size).toBe(ids.length);
    });
});

/*
 * T13 / T14 / T15 / T16 / T17
 */

describe('walking the results from the keyboard', () => {
    it('moves the active option down and up', async () => {
        fetchMock.mockResolvedValue(answer(['Ada', 'Grace', 'Hedy']));

        const { input } = await open();

        expect(options()[0].getAttribute('aria-selected')).toBe('true');

        input.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }),
        );
        await flushPromises();

        expect(options()[1].getAttribute('aria-selected')).toBe('true');
        expect(input.getAttribute('aria-activedescendant')).toBe(
            options()[1].id,
        );

        input.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'ArrowUp', bubbles: true }),
        );
        await flushPromises();

        expect(options()[0].getAttribute('aria-selected')).toBe('true');
    });

    it('jumps to the ends with Home and End', async () => {
        fetchMock.mockResolvedValue(answer(['Ada', 'Grace', 'Hedy']));

        const { input } = await open();

        input.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'End', bubbles: true }),
        );
        await flushPromises();

        expect(options()[2].getAttribute('aria-selected')).toBe('true');

        input.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Home', bubbles: true }),
        );
        await flushPromises();

        expect(options()[0].getAttribute('aria-selected')).toBe('true');
    });

    it('asks the active option to come into view', async () => {
        fetchMock.mockResolvedValue(answer(['Ada', 'Grace']));

        const { input } = await open();

        const scrolled: Element[] = [];

        options().forEach((option) => {
            option.scrollIntoView = function scrollIntoView(this: Element) {
                scrolled.push(this);
            } as Element['scrollIntoView'];
        });

        input.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }),
        );
        await flushPromises();

        // happy-dom has no layout, so what is proved is that the right element
        // was asked — not that anything moved. That is browser behaviour and
        // is not verified here.
        expect(scrolled).toEqual([options()[1]]);
    });
});

/*
 * T19 / T20 / T21 / T22 / T23 / T24 / T25
 */

describe('what the palette says happened', () => {
    it('counts what it found', async () => {
        fetchMock.mockResolvedValue(answer(['Ada', 'Grace']));

        await open();

        expect(status()).toBe('2 result(s)');
    });

    it('separates found-nothing from asked-nothing', async () => {
        fetchMock.mockResolvedValue(answer([]));

        await open('an');

        expect(status()).toBe('Nothing found.');
        expect(document.body.textContent).toContain('Nothing found.');
        expect(document.body.textContent).not.toContain(
            'Type at least two characters',
        );
    });

    it('says a refusal is a refusal, not an empty result', async () => {
        fetchMock.mockResolvedValue({ ok: false, json: async () => ({}) });

        await open();

        // This is the whole finding: `groups = []` on a non-OK response made a
        // server error and "no matches" the same screen.
        expect(document.body.textContent).toContain(
            'The search could not be run.',
        );
        expect(document.body.textContent).not.toContain('Nothing found.');
        expect(status()).toBe('The search could not be run.');
    });

    it('says a network failure is a failure', async () => {
        fetchMock.mockRejectedValue(new Error('offline'));

        await open();

        expect(document.body.textContent).toContain(
            'The search could not be run.',
        );
    });

    it('marks results that belong to an earlier query', async () => {
        fetchMock.mockResolvedValueOnce(answer(['Ada']));

        const { input } = await open('ad');

        expect(options()).toHaveLength(1);

        fetchMock.mockRejectedValueOnce(new Error('offline'));

        input.value = 'bob';
        input.dispatchEvent(new Event('input'));
        await flushPromises();
        await new Promise((resolve) => setTimeout(resolve, 5));
        await flushPromises();

        // Ada is still readable, which is better than blanking the panel —
        // but she is not the answer to "bob", and the palette now says so.
        expect(options()).toHaveLength(1);
        expect(document.body.textContent).toContain('Showing earlier results');
    });

    it('retries the same query and recovers', async () => {
        fetchMock.mockRejectedValueOnce(new Error('offline'));

        await open('employee');

        expect(document.body.textContent).toContain(
            'The search could not be run.',
        );

        fetchMock.mockResolvedValueOnce(answer(['Ada']));

        const retry = [...document.querySelectorAll('button')].find(
            (button) => button.textContent?.trim() === 'Try again',
        );

        retry?.click();
        await flushPromises();

        expect(fetchMock.mock.calls.at(-1)?.[0]).toContain('q=employee');
        expect(options()).toHaveLength(1);
        expect(status()).toBe('1 result(s)');
    });
});
