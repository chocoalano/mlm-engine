<script setup lang="ts">
import { computed, ref, useId } from 'vue';

import { Card, CardContent } from '@/components/ui/card';

import type {
    ChartOptions,
    ChartSeriesDefinition,
    ChartVariant,
    StatColor,
} from '@/panel/types/widget';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

const props = withDefaults(
    defineProps<{
        variant: ChartVariant;
        labels: string[];
        series: ChartSeriesDefinition[];
        /** A closed set of settings, never a configuration tree. */
        options?: ChartOptions;
        maxHeight?: number;
    }>(),
    {
        options: () => ({
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
        }),
        maxHeight: 220,
    },
);

const WIDTH = 600;
const HEIGHT = 220;

const PADDING = {
    top: 16,
    right: 8,
    bottom: 12,
    left: 8,
};

const GRID_LINES = 4;

const activeIndex = ref<number | null>(null);

/**
 * Whether the data table under the chart is open.
 *
 * A disclosure rather than a permanently visible table, and rather than a
 * visually hidden one. Visible-on-demand serves both readers: somebody who
 * cannot use the picture can reach the numbers, and somebody who can is not
 * given a second copy of the dashboard they did not ask for. A visually hidden
 * table would serve only the first and would be invisible to a sighted user
 * who simply wants the exact figures.
 */
const showData = ref(false);

/**
 * What a series is drawn in.
 *
 * The stylesheet already ships a category palette — `--chart-1` through
 * `--chart-5` — and this widget was not using it. It was mapping the *status*
 * vocabulary onto data series instead: a series called `danger` was red
 * because the name said danger, not because the data meant anything bad.
 *
 * Those are two different jobs. A status colour answers "is this good?"; a
 * category colour answers "which line is this?". Using the first for the
 * second means a dashboard of four neutral metrics reads as one alarm and one
 * warning, and it leaves nothing to say when a series genuinely *is* an alarm.
 *
 * The declared colour still chooses the hue, so an application that said
 * `success` still gets a green line — the names are kept, the tokens behind
 * them are now the category palette.
 */
const SERIES_CLASSES: Record<StatColor, string> = {
    default: 'text-chart-1',
    success: 'text-chart-2',
    warning: 'text-chart-4',
    danger: 'text-chart-5',
    info: 'text-chart-3',
};

/**
 * A second distinction, for anyone who cannot use the first.
 *
 * Colour alone separated the series. Dashes are the conventional companion for
 * lines and are applied only where they mean something: a bar or an area has
 * no stroke to dash, so they get nothing rather than a decorative pattern.
 */
const SERIES_DASH: string[] = ['', '6 3', '2 3', '8 3 2 3', '1 4'];

function dashFor(index: number): string | undefined {
    if (props.variant === 'bar' || props.series.length < 2) {
        return undefined;
    }

    return SERIES_DASH[index % SERIES_DASH.length] || undefined;
}

const plot = computed(() => ({
    width: WIDTH - PADDING.left - PADDING.right,
    height: HEIGHT - PADDING.top - PADDING.bottom,
}));

const isLine = computed(
    () => props.variant === 'line' || props.variant === 'area',
);

/**
 * Whether this chart stacks.
 *
 * `stacked` is a bar concept and the bar branch of the template is the only
 * thing that reads it, so this has to mean exactly what that branch means: a
 * line or an area is drawn at its own values whatever the option says.
 */
const isStackedBar = computed(() => !isLine.value && props.options.stacked);

const allValues = computed(() =>
    props.series.flatMap((item) =>
        item.values.filter((value) => Number.isFinite(value)),
    ),
);

const pointCount = computed(() =>
    Math.max(
        props.labels.length,
        ...props.series.map((item) => item.values.length),
    ),
);

/**
 * The running total a stacked column reaches, step by step.
 *
 * `stacks[pointIndex][seriesIndex]` is where that segment begins, and the
 * entry after the last series is the whole column. Every entry is therefore a
 * height the column actually reaches — which is what makes this one table
 * enough for both jobs below: the axis is scaled from it and the rectangles
 * are drawn from it, so the scale and the drawing cannot disagree. Computing
 * the same accumulation twice is exactly how they used to.
 *
 * A missing value contributes nothing and does not advance the total, which
 * keeps a gap a gap rather than a zero.
 */
const stacks = computed(() => {
    const table: number[][] = [];

    for (let point = 0; point < pointCount.value; point++) {
        const running = [0];
        let total = 0;

        for (const item of props.series) {
            const value = item.values[point];

            if (typeof value === 'number' && Number.isFinite(value)) {
                total += value;
            }

            running.push(total);
        }

        table.push(running);
    }

    return table;
});

/**
 * The values the axis has to reach.
 *
 * Grouped bars, lines and areas are each drawn at their own value, so the
 * values are the domain. A stacked column is drawn at a running total, and a
 * total is bigger than the parts it is made of — two series of 60 and 120
 * make a column 180 tall, and an axis that stopped at 120 put the upper
 * segment at y = -59, above the plot and over whatever was drawn there.
 *
 * Every step of the total counts, not only the last one: a stack that climbs
 * to 100 before coming back down to 80 still reached 100, and the segment
 * drawn there has to fit.
 */
const domainValues = computed(() =>
    isStackedBar.value ? stacks.value.flat() : allValues.value,
);

const isEmpty = computed(
    () =>
        props.series.length === 0 ||
        allValues.value.length === 0 ||
        pointCount.value === 0,
);

/**
 * Chart domain.
 *
 * Zero stays visible whenever possible so bars have a meaningful baseline.
 * A small amount of breathing room prevents the highest line from touching
 * the top edge of the chart.
 */
const domain = computed(() => {
    // A pinned axis wins over the data. A chart read against a target needs
    // the same scale every week, which is the whole reason to pin one.
    if (props.options.min !== null && props.options.max !== null) {
        return { min: props.options.min, max: props.options.max };
    }

    if (allValues.value.length === 0) {
        return {
            min: props.options.min ?? 0,
            max: props.options.max ?? 1,
        };
    }

    const rawMin = props.options.min ?? Math.min(...domainValues.value);
    const rawMax = props.options.max ?? Math.max(...domainValues.value);

    let min = Math.min(0, rawMin);
    let max = Math.max(0, rawMax);

    if (min === max) {
        return {
            min,
            max: max + 1,
        };
    }

    const range = max - min;
    const padding = range * 0.08;

    if (max > 0) {
        max += padding;
    }

    if (min < 0) {
        min -= padding;
    }

    return {
        min,
        max,
    };
});

const categoryWidth = computed(() => {
    if (pointCount.value === 0) {
        return plot.value.width;
    }

    return plot.value.width / pointCount.value;
});

function categoryX(index: number): number {
    return PADDING.left + categoryWidth.value * index + categoryWidth.value / 2;
}

function y(value: number): number {
    const range = domain.value.max - domain.value.min;

    if (range === 0) {
        return PADDING.top + plot.value.height / 2;
    }

    return (
        PADDING.top + ((domain.value.max - value) / range) * plot.value.height
    );
}

const baselineY = computed(() => y(0));

/**
 * Horizontal visual guides.
 *
 * No axis labels are rendered here because this widget is intended as a
 * compact dashboard chart rather than a full analytical chart.
 */
const gridLines = computed(() =>
    Array.from({ length: GRID_LINES }, (_, index) => {
        const progress = GRID_LINES <= 1 ? 0 : index / (GRID_LINES - 1);

        return PADDING.top + progress * plot.value.height;
    }),
);

function linePath(values: number[]): string {
    const points = values.map((value, index) => ({
        x: categoryX(index),
        y: y(value),
    }));

    if (points.length === 0) {
        return '';
    }

    if (!props.options.curved || points.length < 3) {
        return points
            .map(
                (point, index) =>
                    `${index === 0 ? 'M' : 'L'}${point.x},${point.y}`,
            )
            .join(' ');
    }

    // A monotone-ish curve through the midpoints: smooth without the
    // overshoot a plain cubic gives, which on a count chart would draw
    // negative values that were never in the data.
    let path = `M${points[0].x},${points[0].y}`;

    for (let index = 1; index < points.length; index++) {
        const previous = points[index - 1];
        const current = points[index];
        const midX = (previous.x + current.x) / 2;

        path += ` C${midX},${previous.y} ${midX},${current.y} ${current.x},${current.y}`;
    }

    return path;
}

/**
 * The line closed down to the baseline, for a filled area.
 *
 * Area is a shape rather than a different chart, which is why it reuses the
 * line's own path and only closes it.
 */
function areaPath(values: number[]): string {
    const line = linePath(values);

    if (line === '') {
        return '';
    }

    const lastX = categoryX(values.length - 1);

    return `${line} L${lastX},${baselineY.value} L${categoryX(0)},${baselineY.value} Z`;
}

/**
 * Where a bar starts when the series are stacked.
 *
 * Stacking is about the total, so each bar begins where the ones before it
 * ended rather than at the baseline.
 */
function stackBase(seriesIndex: number, pointIndex: number): number {
    return stacks.value[pointIndex]?.[seriesIndex] ?? 0;
}

/**
 * The top edge of one stacked segment.
 *
 * A segment spans from where the stack had reached to where it reaches after
 * this value, and SVG measures a rectangle down from its top. For a positive
 * value the top is the end of that span; for a negative one it is the start.
 * Taking the end regardless drew every negative segment one whole segment too
 * low, and the error accumulated down the column.
 */
function stackY(
    seriesIndex: number,
    pointIndex: number,
    value: number,
): number {
    const base = stackBase(seriesIndex, pointIndex);

    return y(Math.max(base, base + value));
}

/** Stacked series share one column, so it takes the whole group's width. */
const stackedBarWidth = computed(() => categoryWidth.value * 0.72);

const isFilled = computed(
    () => props.variant === 'area' || props.options.filled,
);

/**
 * Grouped bar geometry.
 */
const barGap = computed(() => {
    const available = categoryWidth.value * 0.72;

    return Math.min(4, available * 0.04);
});

const barWidth = computed(() => {
    const count = Math.max(props.series.length, 1);
    const available = categoryWidth.value * 0.72;

    const gaps = barGap.value * Math.max(count - 1, 0);

    return Math.max(2, (available - gaps) / count);
});

function barX(seriesIndex: number, pointIndex: number): number {
    const count = Math.max(props.series.length, 1);

    const totalWidth =
        barWidth.value * count + barGap.value * Math.max(count - 1, 0);

    const groupStart = PADDING.left + pointIndex * categoryWidth.value;

    return (
        groupStart +
        (categoryWidth.value - totalWidth) / 2 +
        seriesIndex * (barWidth.value + barGap.value)
    );
}

function barY(value: number): number {
    return Math.min(y(value), baselineY.value);
}

function barHeight(value: number): number {
    return Math.abs(baselineY.value - y(value));
}

function hitAreaX(index: number): number {
    return PADDING.left + index * categoryWidth.value;
}

function hasValue(item: ChartSeriesDefinition, index: number): boolean {
    const value = item.values[index];

    return typeof value === 'number' && Number.isFinite(value);
}

const activeLabel = computed(() => {
    if (activeIndex.value === null) {
        return '';
    }

    return props.labels[activeIndex.value] ?? `Data ${activeIndex.value + 1}`;
});

const activeItems = computed(() => {
    if (activeIndex.value === null) {
        return [];
    }

    return props.series
        .map((item) => ({
            ...item,
            value: item.values[activeIndex.value as number],
        }))
        .filter(
            (
                item,
            ): item is ChartSeriesDefinition & {
                value: number;
            } => typeof item.value === 'number' && Number.isFinite(item.value),
        );
});

const numberFormatter = new Intl.NumberFormat(undefined, {
    maximumFractionDigits: 2,
});

function formatValue(value: number): string {
    return (
        (props.options.prefix ?? '') +
        numberFormatter.format(value) +
        (props.options.suffix ?? '')
    );
}

/**
 * Tooltip follows the active category rather than the raw pointer position.
 *
 * This makes the interaction stable and prevents the tooltip from shaking
 * while the user moves across the same data point.
 */
const tooltipStyle = computed(() => {
    if (activeIndex.value === null) {
        return {};
    }

    const left = (categoryX(activeIndex.value) / WIDTH) * 100;

    let translateX = '-50%';

    if (activeIndex.value === 0) {
        translateX = '0';
    } else if (activeIndex.value === pointCount.value - 1) {
        translateX = '-100%';
    }

    return {
        left: `${left}%`,
        transform: `translateX(${translateX})`,
    };
});

/**
 * One row per category, derived once.
 *
 * The category index used to be recomputed at five separate places — the
 * point, the label, the hit area, the tooltip and the accessible name — and
 * two of them computed it differently. `v-for="(_, index) in pointCount"`
 * yields a *0-based* `index` alongside a 1-based value, so `labels[index - 1]`
 * read `labels[-1]` for the first category: the first hit area was drawn off
 * the left edge and named "Data 0", every label was shifted one place, and the
 * last category had no hit area at all — its label existed in the data and was
 * unreachable by pointer or keyboard.
 *
 * Deriving the model once is what makes that class of bug impossible rather
 * than fixed: there is one index, and everything that needs a category reads
 * this.
 */
const categories = computed(() =>
    Array.from({ length: pointCount.value }, (_, index) => ({
        index,
        label:
            props.labels[index] ?? t('widgets.category', { number: index + 1 }),
        x: categoryX(index),
        hitX: hitAreaX(index),
        /** Only the series that actually have a number here. */
        points: props.series
            .map((item) => ({ series: item, value: item.values[index] }))
            .filter(
                (
                    point,
                ): point is { series: ChartSeriesDefinition; value: number } =>
                    typeof point.value === 'number' &&
                    Number.isFinite(point.value),
            ),
    })),
);

/**
 * What a category is called when it is read rather than looked at.
 *
 * The same formatted values the tooltip shows, from the same function — a
 * tooltip saying "Rp 1.000.000" beside an accessible name saying "1000000"
 * is two answers to one question.
 */
function categoryDescription(
    category: (typeof categories.value)[number],
): string {
    if (category.points.length === 0) {
        return t('widgets.category_empty', { category: category.label });
    }

    const values = category.points
        .map((point) => `${point.series.label}: ${formatValue(point.value)}`)
        .join(', ');

    return `${category.label} — ${values}`;
}

/**
 * What the picture is, said once.
 *
 * It used to be `${variant} chart` — "line chart", built in Vue, in English,
 * whatever the panel's locale. It named the drawing technique and nothing
 * about the data: which series, how many periods, what any of it is.
 *
 * This is a summary, not an insight. It says what is there and stops: the data
 * itself is in the table below, and inventing "sales are improving strongly"
 * from an array of numbers is a claim the widget cannot support.
 */
const chartSummary = computed(() =>
    t('widgets.chart_summary', {
        type: t(`widgets.chart_${props.variant}`),
        series: props.series.length,
        categories: pointCount.value,
    }),
);

/** Unique per instance, so two charts on a dashboard do not share an id. */
const dataTableId = `${useId()}-data`;

/**
 * One cell of the table.
 *
 * Empty rather than zero when the series has no number here: a gap and a zero
 * are different readings, and `values[index] || '—'` would turn every zero
 * into a gap.
 */
function cellValue(item: ChartSeriesDefinition, index: number): string {
    const value = item.values[index];

    return typeof value === 'number' && Number.isFinite(value)
        ? formatValue(value)
        : '';
}

function activate(index: number): void {
    activeIndex.value = index;
}

function deactivate(): void {
    activeIndex.value = null;
}
</script>

<template>
    <Card
        class="overflow-hidden rounded-lg border-border/70 bg-background/60 py-0 shadow-xs"
    >
        <CardContent class="p-4 sm:p-5">
            <!-- Legend -->
            <div
                v-if="series.length && options.legend"
                class="mb-5 flex flex-wrap items-center gap-x-5 gap-y-2 border-b border-border/60 pb-4"
            >
                <div
                    v-for="item in series"
                    :key="item.label"
                    class="flex items-center gap-2 text-xs font-medium text-muted-foreground"
                >
                    <span
                        class="size-2 rounded-full bg-current ring-1 ring-border/60"
                        :class="SERIES_CLASSES[item.color]"
                    />

                    <span>
                        {{ item.label }}
                    </span>
                </div>
            </div>

            <!-- Empty state -->
            <div
                v-if="isEmpty"
                class="flex min-h-48 items-center justify-center rounded-lg border border-dashed border-border/70 bg-muted/15"
            >
                <p class="text-sm text-muted-foreground">
                    {{ t('widgets.no_data') }}
                </p>
            </div>

            <!-- Chart -->
            <div v-else class="relative isolate min-w-0">
                <!-- Floating tooltip -->
                <Transition
                    enter-active-class="transition duration-150 ease-out"
                    enter-from-class="scale-[0.98] opacity-0"
                    enter-to-class="scale-100 opacity-100"
                    leave-active-class="transition duration-100 ease-in"
                    leave-from-class="scale-100 opacity-100"
                    leave-to-class="scale-[0.98] opacity-0"
                >
                    <div
                        v-if="activeIndex !== null && activeItems.length"
                        class="pointer-events-none absolute top-2 z-30 w-52 overflow-hidden rounded-lg border border-border/70 bg-popover/95 text-popover-foreground shadow-lg shadow-black/5 backdrop-blur-md dark:shadow-black/30"
                        :style="tooltipStyle"
                    >
                        <!-- Tooltip heading -->
                        <div
                            class="border-b border-border/60 bg-muted/25 px-3 py-2.5"
                        >
                            <p
                                class="text-[11px] font-medium text-muted-foreground"
                            >
                                {{ activeLabel }}
                            </p>
                        </div>

                        <!-- Tooltip content -->
                        <div class="space-y-2.5 px-3 py-3">
                            <div
                                v-for="item in activeItems"
                                :key="item.label"
                                class="flex items-center justify-between gap-4"
                            >
                                <div class="flex min-w-0 items-center gap-2">
                                    <span
                                        class="size-2 shrink-0 rounded-full bg-current"
                                        :class="SERIES_CLASSES[item.color]"
                                    />

                                    <span
                                        class="truncate text-xs text-muted-foreground"
                                    >
                                        {{ item.label }}
                                    </span>
                                </div>

                                <span
                                    class="shrink-0 text-sm font-semibold tabular-nums"
                                >
                                    {{ formatValue(item.value) }}
                                </span>
                            </div>
                        </div>
                    </div>
                </Transition>

                <svg
                    :viewBox="`0 0 ${WIDTH} ${HEIGHT}`"
                    class="w-full overflow-visible select-none"
                    :style="{ height: `${maxHeight}px` }"
                    role="img"
                    :aria-label="chartSummary"
                    preserveAspectRatio="none"
                    @pointerleave="deactivate"
                >
                    <!-- Horizontal grid -->
                    <g
                        v-if="options.grid"
                        aria-hidden="true"
                        class="text-border/50"
                    >
                        <line
                            v-for="gridY in gridLines"
                            :key="gridY"
                            :x1="PADDING.left"
                            :x2="WIDTH - PADDING.right"
                            :y1="gridY"
                            :y2="gridY"
                            stroke="currentColor"
                            stroke-width="1"
                            stroke-dasharray="3 5"
                            vector-effect="non-scaling-stroke"
                        />
                    </g>

                    <!-- Active vertical crosshair -->
                    <line
                        v-if="activeIndex !== null"
                        :x1="categoryX(activeIndex)"
                        :x2="categoryX(activeIndex)"
                        :y1="PADDING.top"
                        :y2="HEIGHT - PADDING.bottom"
                        stroke="currentColor"
                        stroke-width="1"
                        stroke-dasharray="3 4"
                        class="text-foreground/15"
                        vector-effect="non-scaling-stroke"
                    />

                    <!-- LINE and AREA -->
                    <template v-if="isLine">
                        <g v-if="isFilled">
                            <path
                                v-for="item in series"
                                :key="`area-${item.label}`"
                                :d="areaPath(item.values)"
                                fill="currentColor"
                                class="opacity-15"
                                :class="SERIES_CLASSES[item.color]"
                            />
                        </g>

                        <path
                            v-for="(item, seriesIndex) in series"
                            :key="item.label"
                            :d="linePath(item.values)"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linejoin="round"
                            stroke-linecap="round"
                            :stroke-dasharray="dashFor(seriesIndex)"
                            :class="SERIES_CLASSES[item.color]"
                            vector-effect="non-scaling-stroke"
                        />

                        <!-- Active dots -->
                        <template v-if="activeIndex !== null">
                            <circle
                                v-for="item in series"
                                v-show="hasValue(item, activeIndex)"
                                :key="`active-${item.label}`"
                                :cx="categoryX(activeIndex)"
                                :cy="y(item.values[activeIndex])"
                                r="4"
                                fill="currentColor"
                                stroke="var(--background)"
                                stroke-width="2.5"
                                :class="SERIES_CLASSES[item.color]"
                                vector-effect="non-scaling-stroke"
                            />
                        </template>
                    </template>

                    <!-- BAR -->
                    <template v-else>
                        <template
                            v-for="(item, seriesIndex) in series"
                            :key="item.label"
                        >
                            <rect
                                v-for="(value, pointIndex) in item.values"
                                :key="`${item.label}-${pointIndex}`"
                                :x="
                                    options.stacked
                                        ? barX(0, pointIndex)
                                        : barX(seriesIndex, pointIndex)
                                "
                                :y="
                                    options.stacked
                                        ? stackY(seriesIndex, pointIndex, value)
                                        : barY(value)
                                "
                                :width="
                                    options.stacked ? stackedBarWidth : barWidth
                                "
                                :height="barHeight(value)"
                                rx="3"
                                fill="currentColor"
                                :class="[
                                    SERIES_CLASSES[item.color],
                                    activeIndex !== null &&
                                    activeIndex !== pointIndex
                                        ? 'opacity-35'
                                        : 'opacity-90',
                                ]"
                                class="transition-opacity duration-150"
                            />
                        </template>
                    </template>

                    <!--
                        Invisible category hit areas.

                        These make interaction much easier than requiring
                        users to hit a 2px line or narrow bar exactly.
                    -->
                    <!--
                        `role="button"` was wrong: activating one of these does
                        nothing. They reveal the tooltip on hover and on focus,
                        which is what an image with a description does, not
                        what a button does — and announcing "button" promises an
                        action that pressing will not perform.

                        The name carries the category *and* its values, from
                        the same formatter the tooltip uses, so focusing a
                        point tells you what the tooltip would have shown.
                    -->
                    <rect
                        v-for="category in categories"
                        :key="`hit-area-${category.index}`"
                        :x="category.hitX"
                        :y="0"
                        :width="categoryWidth"
                        :height="HEIGHT"
                        fill="transparent"
                        tabindex="0"
                        role="img"
                        :aria-label="categoryDescription(category)"
                        @pointerenter="activate(category.index)"
                        @pointerleave="deactivate"
                        @focus="activate(category.index)"
                        @blur="deactivate"
                    />
                </svg>

                <!--
                    The data, as data.

                    A chart encodes values as position and colour, and neither
                    survives being read aloud. Forty individual focus stops
                    would technically expose the numbers and would be a worse
                    way to read them than a table — which is why the points are
                    focusable for inspection *and* the whole set is here in a
                    structure built for reading across.
                -->
                <div class="mt-4">
                    <button
                        type="button"
                        class="rounded-sm text-xs font-medium text-muted-foreground underline underline-offset-4 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        :aria-expanded="showData"
                        :aria-controls="dataTableId"
                        @click="showData = !showData"
                    >
                        {{
                            showData
                                ? t('widgets.hide_data')
                                : t('widgets.show_data')
                        }}
                    </button>

                    <div
                        v-show="showData"
                        :id="dataTableId"
                        class="mt-3 overflow-x-auto"
                    >
                        <table class="w-full text-left text-xs">
                            <caption class="sr-only">
                                {{
                                    t('widgets.chart_data')
                                }}
                            </caption>
                            <thead class="text-muted-foreground">
                                <tr>
                                    <th
                                        scope="col"
                                        class="py-1 pr-3 font-medium"
                                    >
                                        {{ t('widgets.period') }}
                                    </th>
                                    <th
                                        v-for="item in series"
                                        :key="`col-${item.label}`"
                                        scope="col"
                                        class="py-1 pr-3 text-right font-medium"
                                    >
                                        {{ item.label }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="tabular-nums">
                                <tr
                                    v-for="category in categories"
                                    :key="`row-${category.index}`"
                                    class="border-t border-border/60"
                                >
                                    <th
                                        scope="row"
                                        class="py-1 pr-3 font-normal text-muted-foreground"
                                    >
                                        {{ category.label }}
                                    </th>
                                    <!--
                                        Read straight from the series so a
                                        zero renders as 0 and a gap renders as
                                        blank — filtering on truthiness here
                                        would delete every zero in the data.
                                    -->
                                    <td
                                        v-for="item in series"
                                        :key="`cell-${category.index}-${item.label}`"
                                        class="py-1 pr-3 text-right"
                                    >
                                        {{ cellValue(item, category.index) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- X labels -->
                <div
                    class="mt-2 grid text-[11px] text-muted-foreground/70"
                    :style="{
                        gridTemplateColumns: `repeat(${pointCount}, minmax(0, 1fr))`,
                    }"
                >
                    <span
                        v-for="category in categories"
                        :key="`label-${category.index}`"
                        class="truncate px-1 text-center"
                    >
                        {{ labels[category.index] ?? '' }}
                    </span>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
