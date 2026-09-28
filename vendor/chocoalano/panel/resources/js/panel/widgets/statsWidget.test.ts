/**
 * @vitest-environment happy-dom
 */
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { StatDefinition } from '@/panel/types/widget';

/**
 * Whether a figure moving is good news.
 *
 * The widget answered that from the direction alone: up was green, down was
 * red. For revenue and signups the assumption is invisible. For cost, churn,
 * error rate, downtime and complaints it is exactly backwards — a rising error
 * rate was reported as an improvement, in green, and the worse it got the
 * greener it looked.
 *
 * Direction is arithmetic. Meaning is a statement somebody has to make, and
 * until they do the honest colour is neither.
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
                    increased: 'Increased',
                    decreased: 'Decreased',
                    unchanged: 'Unchanged',
                    trend_positive: 'an improvement',
                    trend_negative: 'a decline',
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

const { default: StatsWidget } =
    await import('@/panel/widgets/StatsWidget.vue');

const mounted: Array<{ unmount: () => void }> = [];

afterEach(() => {
    while (mounted.length > 0) {
        mounted.pop()?.unmount();
    }

    document.body.innerHTML = '';
});

function stat(trend: Record<string, unknown> | null): StatDefinition {
    return {
        label: 'Metric',
        value: 100,
        display: '100',
        description: null,
        icon: null,
        color: 'default',
        trend,
        chart: [],
        url: null,
    } as unknown as StatDefinition;
}

function render(definition: StatDefinition) {
    const wrapper = mount(StatsWidget, {
        attachTo: document.body,
        props: { stats: [definition] },
    });

    mounted.push(wrapper);

    return wrapper;
}

/** The trend badge: the element carrying the accessible name. */
function badge(wrapper: ReturnType<typeof render>) {
    return wrapper.get('[aria-label]');
}

function icons(wrapper: ReturnType<typeof render>): string {
    return wrapper
        .findAll('svg')
        .map((svg) => svg.html())
        .join('');
}

/*
 * T1 / T2 / T3 / T4 — direction and meaning are independent
 */

describe('what a movement means', () => {
    it('is good when a metric that should rise, rises', () => {
        const wrapper = render(
            stat({ direction: 'up', value: 12.4, sentiment: 'positive' }),
        );

        expect(badge(wrapper).classes()).toContain('text-success');
        expect(badge(wrapper).attributes('aria-label')).toBe(
            'Increased 12.4% — an improvement',
        );
    });

    it('is bad when a metric that should fall, rises', () => {
        const wrapper = render(
            stat({ direction: 'up', value: 12.4, sentiment: 'negative' }),
        );

        // The case the old behaviour got backwards: infrastructure cost up
        // 12.4% was reported in green.
        expect(badge(wrapper).classes()).toContain('text-destructive');
        expect(badge(wrapper).attributes('aria-label')).toBe(
            'Increased 12.4% — a decline',
        );
    });

    it('is good when a metric that should fall, falls', () => {
        const wrapper = render(
            stat({ direction: 'down', value: 30, sentiment: 'positive' }),
        );

        expect(badge(wrapper).classes()).toContain('text-success');
    });

    it('is bad when a metric that should rise, falls', () => {
        const wrapper = render(
            stat({ direction: 'down', value: 8, sentiment: 'negative' }),
        );

        expect(badge(wrapper).classes()).toContain('text-destructive');
    });
});

/*
 * T5 / T6 / T9 — saying nothing
 */

describe('a figure nobody described', () => {
    it('is coloured neither way', () => {
        const wrapper = render(stat({ direction: 'up', value: 5 }));

        // No sentiment on the payload — an older server, or an author who
        // never said. Green would be a claim the widget cannot support.
        expect(badge(wrapper).classes()).toContain('text-muted-foreground');
        expect(badge(wrapper).classes()).not.toContain('text-success');
        expect(badge(wrapper).classes()).not.toContain('text-destructive');
    });

    it('describes the movement without interpreting it', () => {
        const wrapper = render(stat({ direction: 'up', value: 5 }));

        // "Increased 5%" is true whatever it means. Nothing follows it.
        expect(badge(wrapper).attributes('aria-label')).toBe('Increased 5%');
    });

    it('says nothing about a figure that did not move', () => {
        const wrapper = render(stat({ direction: 'neutral', value: 0 }));

        expect(badge(wrapper).attributes('aria-label')).toBe('Unchanged 0%');
        expect(badge(wrapper).classes()).toContain('text-muted-foreground');
    });
});

/*
 * T7 / T8 — the arrow and the colour answer different questions
 */

describe('the arrow', () => {
    it('points the way the number went, whatever it means', () => {
        const good = render(
            stat({ direction: 'up', value: 10, sentiment: 'positive' }),
        );
        const bad = render(
            stat({ direction: 'up', value: 10, sentiment: 'negative' }),
        );

        // Both rose. One is welcome and one is not, and the arrow is the same
        // arrow — it is reporting arithmetic, not judgement.
        expect(icons(good)).toBe(icons(bad));
        expect(badge(good).classes()).not.toEqual(badge(bad).classes());
    });

    it('is a down arrow for a welcome fall', () => {
        const down = render(
            stat({ direction: 'down', value: 30, sentiment: 'positive' }),
        );
        const up = render(
            stat({ direction: 'up', value: 30, sentiment: 'positive' }),
        );

        expect(icons(down)).not.toBe(icons(up));
        // Same meaning, so the same colour, despite opposite directions.
        expect(badge(down).classes()).toEqual(badge(up).classes());
    });
});

/*
 * T10 — the payload that predates all of this
 */

describe('an older payload', () => {
    it('still renders', () => {
        const wrapper = render(stat({ direction: 'up', value: 12.4 }));

        expect(wrapper.text()).toContain('100');
        expect(wrapper.text()).toContain('12.4%');
    });

    it('still renders a stat with no trend at all', () => {
        const wrapper = render(stat(null));

        expect(wrapper.text()).toContain('100');
        expect(wrapper.find('[aria-label]').exists()).toBe(false);
    });
});

/*
 * The colour vocabularies UI-1 left split
 */

describe('a stat that declares a status colour', () => {
    it('draws it from the semantic tokens, not a literal palette', () => {
        const wrapper = render({
            ...stat(null),
            color: 'danger',
        } as unknown as StatDefinition);

        // A stat marked `danger` is saying the figure is bad news, which *is*
        // the status vocabulary — so it belongs on the tokens a panel can
        // re-theme, not on `text-red-600`. The accent stripe is the part that
        // renders without an icon.
        expect(wrapper.html()).toContain('border-l-destructive');
        expect(wrapper.html()).not.toContain('text-red-');
        expect(wrapper.html()).not.toContain('emerald');
    });

    it('uses the panel accent for the one status with no token', () => {
        const wrapper = render({
            ...stat(null),
            color: 'info',
        } as unknown as StatDefinition);

        // `info` has no semantic token, and inventing one would mean adding a
        // colour to the theme, the allowlist and the contrast tests for a
        // single widget.
        expect(wrapper.html()).toContain('border-l-primary');
        expect(wrapper.html()).not.toContain('sky-');
    });
});
