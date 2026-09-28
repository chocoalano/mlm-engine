/**
 * @vitest-environment happy-dom
 */
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { ChartSeriesDefinition } from '@/panel/types/widget';

/**
 * Which category a point belongs to, and what it says when nobody looks.
 *
 * `v-for="(_, index) in pointCount"` yields a *0-based* `index` beside a
 * 1-based value, so `labels[index - 1]` read `labels[-1]` for the first
 * category. The consequence was not subtle: the first hit area was drawn off
 * the left edge of the chart and named "Data 0", every visible label sat one
 * place to the left, and the last category had no hit area at all — its label
 * was in the data and unreachable by pointer or keyboard.
 *
 * The line itself was drawn correctly, which is why it survived: the picture
 * looked right and everything around it was off by one.
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
                widgets: {
                    no_data: 'No data',
                    chart_summary:
                        ':type, :series series across :categories periods',
                    chart_line: 'Line chart',
                    chart_bar: 'Bar chart',
                    chart_area: 'Area chart',
                    category: 'Period :number',
                    category_empty: ':category — no data',
                    chart_data: 'Chart data',
                    show_data: 'View data',
                    hide_data: 'Hide data',
                    period: 'Period',
                },
            },
        },
        url: '/',
        component: '',
        version: null,
    }),
    Link: { name: 'Link', template: '<a><slot /></a>' },
}));

const { default: ChartWidget } =
    await import('@/panel/widgets/ChartWidget.vue');

const mounted: Array<{ unmount: () => void }> = [];

afterEach(() => {
    while (mounted.length > 0) {
        mounted.pop()?.unmount();
    }

    document.body.innerHTML = '';
});

function series(
    label: string,
    values: Array<number | null>,
    color = 'default',
): ChartSeriesDefinition {
    return { label, color, values } as unknown as ChartSeriesDefinition;
}

function render(props: Record<string, unknown> = {}) {
    const wrapper = mount(ChartWidget, {
        attachTo: document.body,
        props: {
            variant: 'line',
            labels: ['Jan', 'Feb', 'Mar'],
            series: [series('Revenue', [100, 120, 90])],
            ...props,
        },
    });

    mounted.push(wrapper);

    return wrapper;
}

/** The invisible per-category targets, in document order. */
function targets(): HTMLElement[] {
    return [...document.querySelectorAll<HTMLElement>('rect[tabindex="0"]')];
}

function names(): string[] {
    return targets().map((rect) => rect.getAttribute('aria-label') ?? '');
}

function axisLabels(wrapper: ReturnType<typeof render>): string[] {
    return wrapper
        .findAll('.grid span')
        .map((span) => span.text())
        .filter((text) => text !== '');
}

function tooltipHeading(): string {
    return (
        document.querySelector('.absolute.top-2 p')?.textContent?.trim() ?? ''
    );
}

/*
 * C1 / C2 / C5 / C10 — the mapping itself
 */

describe('which category a point is', () => {
    it('names every category, including the last', () => {
        render();

        // "Mar" used to have no target at all: three categories produced
        // targets named "Data 0", "Jan", "Feb".
        expect(names()).toHaveLength(3);
        expect(names()[0]).toContain('Jan');
        expect(names()[1]).toContain('Feb');
        expect(names()[2]).toContain('Mar');
    });

    it('draws every target inside the chart', () => {
        render();

        const xs = targets().map((rect) => Number(rect.getAttribute('x')));

        // The first was at PADDING.left + (-1 × categoryWidth) — off the left
        // edge, where nothing can reach it.
        expect(xs.every((x) => x >= 0)).toBe(true);
        expect(xs[0]).toBeLessThan(xs[1]);
        expect(xs[1]).toBeLessThan(xs[2]);
    });

    it('puts the visible labels under their own categories', () => {
        const wrapper = render();

        expect(axisLabels(wrapper)).toEqual(['Jan', 'Feb', 'Mar']);
    });

    it('handles a single category without collapsing', () => {
        const wrapper = render({
            labels: ['Jan'],
            series: [series('Revenue', [100])],
        });

        expect(names()).toHaveLength(1);
        expect(names()[0]).toContain('Jan');
        expect(axisLabels(wrapper)).toEqual(['Jan']);

        const x = Number(targets()[0].getAttribute('x'));

        expect(Number.isFinite(x)).toBe(true);
    });

    it('falls back to a numbered period when a label is missing', () => {
        render({ labels: [], series: [series('Revenue', [100, 120])] });

        // Translated and 1-based, rather than the English "Data 0" that came
        // out of the off-by-one.
        expect(names()[0]).toContain('Period 1');
        expect(names()[1]).toContain('Period 2');
    });
});

/*
 * C3 / C4 — the tooltip and the target agree
 */

describe('inspecting a category', () => {
    it('shows the category the pointer is over', async () => {
        render();

        await targets()[2].dispatchEvent(
            new Event('pointerenter', { bubbles: true }),
        );
        await flushPromises();

        expect(tooltipHeading()).toBe('Mar');
    });

    it('shows the same one the keyboard reaches', async () => {
        render();

        targets()[2].dispatchEvent(new Event('focus', { bubbles: true }));
        await flushPromises();

        // Pointer and keyboard drive the same state through the same
        // function; there is no second tooltip builder to drift.
        expect(tooltipHeading()).toBe('Mar');
    });
});

/*
 * C6 / C7 / C8 / C9 — the values themselves
 */

describe('values a naive implementation loses', () => {
    it('keeps a zero', async () => {
        const wrapper = render({ series: [series('Revenue', [0, 120, 90])] });

        expect(names()[0]).toContain('0');

        await wrapper.get('button').trigger('click');

        expect(wrapper.find('tbody').text()).toContain('0');
    });

    it('keeps a negative value', () => {
        render({ series: [series('Profit', [-50, 120, 90])] });

        expect(names()[0]).toContain('-50');
    });

    it('maps every series to the same categories', () => {
        render({
            series: [
                series('Revenue', [100, 120, 90]),
                series('Cost', [80, 85, 95]),
            ],
        });

        expect(names()[0]).toContain('Jan');
        expect(names()[0]).toContain('Revenue: 100');
        expect(names()[0]).toContain('Cost: 80');
        expect(names()[2]).toContain('Mar');
        expect(names()[2]).toContain('Revenue: 90');
    });

    it('says a category is empty rather than inventing a number', () => {
        render({ series: [series('Revenue', [null, 120, 90])] });

        expect(names()[0]).toBe('Jan — no data');
    });
});

/*
 * U13 — A1 / A2 / A3 / A4 / A5 / A8 / A9 / A10 / A11 / A12 / A13 / A14
 */

describe('a chart somebody cannot see', () => {
    it('says what it is, not how it was drawn', () => {
        render();

        const svg = document.querySelector('svg[role="img"]');

        // It was `${variant} chart` — "line chart", built in Vue, in English
        // whatever the panel's locale, naming the drawing technique and
        // nothing about the data.
        expect(svg?.getAttribute('aria-label')).toBe(
            'Line chart, 1 series across 3 periods',
        );
    });

    it('claims nothing the data does not say', () => {
        render();

        const summary = document
            .querySelector('svg[role="img"]')
            ?.getAttribute('aria-label');

        // A summary, not an insight: "sales are improving strongly" is not
        // something an array of numbers supports.
        expect(summary).not.toMatch(/improv|strong|good|bad|better|worse/i);
    });

    it('does not call an inert target a button', () => {
        render();

        // Activating one of these does nothing — they reveal the tooltip on
        // hover and focus. Announcing "button" promises a press that will not
        // do anything.
        expect(targets()[0].getAttribute('role')).toBe('img');
        expect(targets()[0].getAttribute('role')).not.toBe('button');
    });

    it('names a target with its category and its values', () => {
        render({
            series: [
                series('Revenue', [100, 120, 90]),
                series('Cost', [80, 85, 95]),
            ],
        });

        expect(names()[1]).toBe('Feb — Revenue: 120, Cost: 85');
    });

    it('formats those values the way the tooltip does', async () => {
        render({
            series: [series('Revenue', [1000, 120, 90])],
            options: {
                legend: true,
                grid: true,
                stacked: false,
                filled: false,
                curved: false,
                labels: false,
                min: null,
                max: null,
                prefix: 'Rp ',
                suffix: null,
            },
        });

        // One formatter. A tooltip reading "Rp 1.000" beside a name reading
        // "1000" is two answers to one question.
        expect(names()[0]).toContain('Rp 1,000');

        targets()[0].dispatchEvent(new Event('focus', { bubbles: true }));
        await flushPromises();

        expect(document.body.textContent).toContain('Rp 1,000');
    });

    it('offers the whole dataset as a table', async () => {
        const wrapper = render({
            series: [
                series('Revenue', [100, 0, -50]),
                series('Cost', [80, 85, 95]),
            ],
        });

        await wrapper.get('button').trigger('click');

        const table = wrapper.get('table');

        expect(table.find('caption').text()).toBe('Chart data');
        expect(table.findAll('thead th').map((th) => th.text())).toEqual([
            'Period',
            'Revenue',
            'Cost',
        ]);

        const rows = table.findAll('tbody tr').map((row) => row.text());

        expect(rows[0]).toContain('Jan');
        expect(rows[0]).toContain('100');
        // A zero and a negative both survive: `values[i] || '—'` would turn
        // the zero into a gap.
        expect(rows[1]).toContain('0');
        expect(rows[2]).toContain('-50');
    });

    it('leaves a gap blank rather than calling it zero', async () => {
        const wrapper = render({
            series: [series('Revenue', [null, 120, 90])],
        });

        await wrapper.get('button').trigger('click');

        const cells = wrapper.findAll('tbody tr')[0].findAll('td');

        expect(cells[0].text()).toBe('');
    });

    it('keeps the table out of the way until it is asked for', async () => {
        const wrapper = render();

        const toggle = wrapper.get('button');

        expect(toggle.attributes('aria-expanded')).toBe('false');
        expect(toggle.text()).toBe('View data');

        await toggle.trigger('click');

        expect(toggle.attributes('aria-expanded')).toBe('true');
        expect(toggle.text()).toBe('Hide data');
        expect(
            document.getElementById(
                toggle.attributes('aria-controls') as string,
            ),
        ).not.toBeNull();
    });

    it('adds one focus stop per category, not one per value', () => {
        render({
            series: [
                series('Revenue', [100, 120, 90]),
                series('Cost', [80, 85, 95]),
            ],
        });

        // Six values, three focus stops. A stop per value would be a better
        // technical exposure and a worse way to read a chart; the table is
        // where reading across belongs.
        expect(targets()).toHaveLength(3);
    });

    it('renders a coherent empty state rather than a broken drawing', () => {
        const wrapper = render({ labels: [], series: [] });

        expect(wrapper.text()).toContain('No data');
        expect(wrapper.find('svg[role="img"]').exists()).toBe(false);
        expect(wrapper.html()).not.toContain('NaN');
    });

    it('names its series in the legend as text', () => {
        const wrapper = render({
            series: [
                series('Revenue', [100, 120, 90]),
                series('Cost', [80, 85, 95]),
            ],
        });

        expect(wrapper.text()).toContain('Revenue');
        expect(wrapper.text()).toContain('Cost');
    });

    it('separates series by more than colour', () => {
        const wrapper = render({
            series: [
                series('Revenue', [100, 120, 90]),
                series('Cost', [80, 85, 95]),
            ],
        });

        const dashes = wrapper
            .findAll('path[stroke="currentColor"]')
            .map((path) => path.attributes('stroke-dasharray'));

        // The first line is solid and the second is not, so the two are
        // distinguishable without colour perception.
        expect(dashes[0]).toBeUndefined();
        expect(dashes[1]).toBeTruthy();
    });

    it('does not dash a single series for no reason', () => {
        const wrapper = render();

        const dashes = wrapper
            .findAll('path[stroke="currentColor"]')
            .map((path) => path.attributes('stroke-dasharray'));

        expect(dashes).toEqual([undefined]);
    });

    it('colours ordinary series from the category palette', () => {
        const wrapper = render({
            series: [
                series('Revenue', [100, 120, 90], 'success'),
                series('Cost', [80, 85, 95], 'danger'),
            ],
        });

        // A series named `danger` is a category, not an alarm. Using the
        // status vocabulary here left nothing to say when a series genuinely
        // is one, and made four neutral metrics read as a warning and a fault.
        expect(wrapper.html()).toContain('text-chart-');
        expect(wrapper.html()).not.toContain('text-red-500');
        expect(wrapper.html()).not.toContain('text-emerald-500');
    });
});
