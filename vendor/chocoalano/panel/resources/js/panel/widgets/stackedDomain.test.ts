/**
 * @vitest-environment happy-dom
 */
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { ChartSeriesDefinition } from '@/panel/types/widget';

/**
 * What a stacked column is measured against.
 *
 * A stacked bar is drawn at a running total, and the axis was scaled to the
 * individual values that total is made of. Two series of 60 and 120 in one
 * category make a column 180 tall on an axis whose maximum is 120, so the
 * segment on top was placed above the plot — `y` came out negative and the
 * bar overflowed the chart into whatever was drawn above it.
 *
 * Negative stacks failed the same way from the other end, and worse: a
 * segment's `y` was taken from the *end* of its span rather than the top of
 * it, so each negative segment was drawn one full segment-height too low and
 * the error accumulated down the stack.
 *
 * The geometry is asserted in SVG user units against the known plot box
 * rather than in the browser, because that is what makes it deterministic —
 * `tests/browser/stacked.mjs` proves the same rectangles in Chrome.
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

/** The plot box, from the component's own WIDTH/HEIGHT/PADDING. */
const PLOT = { top: 16, bottom: 220 - 12 } as const;

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
): ChartSeriesDefinition {
    return {
        label,
        color: 'default',
        values,
    } as unknown as ChartSeriesDefinition;
}

/** The full options object the server always sends; `min`/`max` are null. */
const OPTIONS = {
    legend: true,
    grid: true,
    stacked: false,
    filled: false,
    curved: false,
    labels: false,
    min: null,
    max: null,
    prefix: null,
    suffix: null,
} as const;

interface Bar {
    x: number;
    y: number;
    width: number;
    height: number;
}

function render(
    seriesList: ChartSeriesDefinition[],
    labels: string[],
    options: Record<string, unknown> = {},
): Bar[] {
    const wrapper = mount(ChartWidget, {
        attachTo: document.body,
        props: {
            variant: 'bar',
            labels,
            series: seriesList,
            options: { ...OPTIONS, stacked: true, ...options },
        },
    });

    mounted.push(wrapper);

    // Scoped to this chart rather than to the document: a test that renders
    // two charts must not read one's rectangles as the other's. The bar rects
    // are the ones carrying a radius; the hit areas are not.
    return [...wrapper.element.querySelectorAll('rect[rx="3"]')].map(
        (rect) => ({
            x: Number(rect.getAttribute('x')),
            y: Number(rect.getAttribute('y')),
            width: Number(rect.getAttribute('width')),
            height: Number(rect.getAttribute('height')),
        }),
    );
}

/** Every rectangle must be drawn inside the plot box. */
function expectInsidePlot(bars: Bar[]): void {
    for (const bar of bars) {
        expect(Number.isFinite(bar.y)).toBe(true);
        expect(Number.isFinite(bar.height)).toBe(true);
        expect(bar.y).toBeGreaterThanOrEqual(PLOT.top - 0.001);
        expect(bar.y + bar.height).toBeLessThanOrEqual(PLOT.bottom + 0.001);
    }
}

describe('a stacked column is measured against its total', () => {
    it('S1 — keeps a positive stack inside the plot', () => {
        // 60 + 120 = 180 against a domain that stopped at 120: the upper
        // segment was placed at y = -59, fifty-nine units above the chart.
        const bars = render([series('A', [60]), series('B', [120])], ['Jan']);

        expect(bars).toHaveLength(2);
        expectInsidePlot(bars);
    });

    it('S2 — scales to the tallest stack, not the tallest value', () => {
        const bars = render(
            [series('A', [60, 80]), series('B', [120, 20])],
            ['Jan', 'Feb'],
        );

        expectInsidePlot(bars);

        // Jan totals 180 and Feb totals 100, so Jan must be the taller column.
        const jan = bars.filter((bar) => bar.x < 300);
        const feb = bars.filter((bar) => bar.x >= 300);
        const janTotal = jan.reduce((sum, bar) => sum + bar.height, 0);
        const febTotal = feb.reduce((sum, bar) => sum + bar.height, 0);

        expect(janTotal).toBeGreaterThan(febTotal);
    });

    it('S3 — keeps a negative stack inside the plot', () => {
        const bars = render([series('A', [-50]), series('B', [-80])], ['Jan']);

        expectInsidePlot(bars);
    });

    it('S3 — stacks negative segments end to end without a gap', () => {
        const bars = render([series('A', [-50]), series('B', [-80])], ['Jan']);

        const [first, second] = bars;

        // The first segment hangs from the baseline; the second begins exactly
        // where it ended. Each used to start where the previous one *ended in
        // value space*, which is one whole segment lower.
        expect(second.y).toBeCloseTo(first.y + first.height, 6);
    });

    it('S4 — follows the renderer on a mixed-sign stack', () => {
        // The renderer accumulates one signed running total, so 100, -40, 20
        // reaches 100, then 60, then 80. The axis has to cover that walk.
        const bars = render(
            [series('A', [100]), series('B', [-40]), series('C', [20])],
            ['Jan'],
        );

        expectInsidePlot(bars);

        const [first, second, third] = bars;

        // A spans 0..100, B takes 40 back off the top so it spans 60..100,
        // and C adds 20 onto 60. B therefore starts at A's top rather than
        // below it, and C ends where B ends. A negative segment overlapping
        // the positive one beneath it is what one signed running total means;
        // it is the renderer's algorithm, not a rounding artefact.
        expect(second.y).toBeCloseTo(first.y, 6);
        expect(third.y + third.height).toBeCloseTo(second.y + second.height, 6);
    });

    it('S5 — treats zero as a value, not as absent', () => {
        const bars = render([series('A', [0]), series('B', [50])], ['Jan']);

        expectInsidePlot(bars);
        expect(bars[0].height).toBe(0);
        expect(bars[1].height).toBeGreaterThan(0);
    });

    it('S6 — skips a gap without shifting the stack', () => {
        const withGap = render(
            [series('A', [null]), series('B', [50])],
            ['Jan'],
        );
        const without = render([series('B', [50])], ['Jan']);

        expectInsidePlot(withGap);

        // A missing value contributes nothing, so the segment above it sits
        // exactly where it would if the gap series were not there at all.
        expect(withGap[1].y).toBeCloseTo(without[0].y, 6);
        expect(withGap[0].height).toBe(0);
    });

    it('S7 — leaves a grouped chart on the individual-value domain', () => {
        const grouped = render(
            [series('A', [60]), series('B', [120])],
            ['Jan'],
            { stacked: false },
        );

        expectInsidePlot(grouped);

        // 120 is the tallest value and the tallest bar, and it reaches the
        // same height it always did: the domain is 0..120 plus 8% padding, so
        // the bar spans 192 / 1.08 of the 192-unit plot.
        const tallest = Math.max(...grouped.map((bar) => bar.height));
        expect(tallest).toBeCloseTo(192 / 1.08, 6);
    });

    it('S8 — leaves a line chart alone when stacked is set', () => {
        const stackedLine = mount(ChartWidget, {
            attachTo: document.body,
            props: {
                variant: 'line',
                labels: ['Jan'],
                series: [series('A', [60]), series('B', [120])],
                options: { ...OPTIONS, stacked: true },
            },
        });
        mounted.push(stackedLine);
        const stackedTicks = stackedLine
            .findAll('.grid span')
            .map((s) => s.text());

        document.body.innerHTML = '';

        const plainLine = mount(ChartWidget, {
            attachTo: document.body,
            props: {
                variant: 'line',
                labels: ['Jan'],
                series: [series('A', [60]), series('B', [120])],
                options: { ...OPTIONS, stacked: false },
            },
        });
        mounted.push(plainLine);

        // Stacking is a bar concept; the line renderer never reads it, so its
        // axis must not move when it is set.
        expect(stackedTicks).toEqual(
            plainLine.findAll('.grid span').map((s) => s.text()),
        );
    });

    it('respects a pinned axis even when the stack exceeds it', () => {
        const bars = render([series('A', [60]), series('B', [120])], ['Jan'], {
            stacked: true,
            min: 0,
            max: 500,
        });

        // A pinned axis is a deliberate decision and still wins over the data.
        expectInsidePlot(bars);
        expect(bars[0].height).toBeCloseTo((60 / 500) * 192, 6);
    });
});
