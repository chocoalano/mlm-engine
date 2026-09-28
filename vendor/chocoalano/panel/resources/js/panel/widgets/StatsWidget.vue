<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowDownRight, ArrowRight, ArrowUpRight } from '@lucide/vue';
import { computed } from 'vue';

import { Card } from '@/components/ui/card';
import { safeUrl } from '@/lib/utils';
import { resolveIcon } from '@/panel/icons/registry';

import type {
    StatColor,
    StatDefinition,
    StatSentiment,
    StatTrend,
} from '@/panel/types/widget';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

const props = defineProps<{
    stats: StatDefinition[];
}>();

/**
 * What a stat's declared colour draws.
 *
 * This one *is* the status vocabulary: a stat marked `success` is saying the
 * figure is good news, and `danger` that it is not. So unlike the chart's
 * series colours — which name categories and now use the category palette —
 * these belong on the semantic tokens UI-1 introduced, and a panel that
 * re-themes `--success` re-themes these with it.
 *
 * `info` has no semantic token of its own, and inventing one would mean
 * adding a colour to the theme, the allowlist and the contrast tests for a
 * single widget. It uses the panel's own accent instead, which is what
 * "notable, not a status" already means everywhere else in the shell.
 *
 * The tinted backgrounds are fractional opacity over an unknown surface. UI-1
 * recorded that class of value as not contrast-measurable by its opaque-pair
 * maths, and that is still true here — these are decorative tints behind an
 * icon, not text on a fill.
 */
const COLOR_CLASSES: Record<
    StatColor,
    {
        icon: string;
        iconBackground: string;
        accent: string;
        dot: string;
    }
> = {
    default: {
        icon: 'text-foreground',
        iconBackground: 'bg-muted ring-border/70',
        accent: 'border-l-muted-foreground/30',
        dot: 'bg-foreground/60',
    },

    success: {
        icon: 'text-success',
        iconBackground: 'bg-success/10 ring-success/10',
        accent: 'border-l-success',
        dot: 'bg-success',
    },

    warning: {
        icon: 'text-warning',
        iconBackground: 'bg-warning/10 ring-warning/10',
        accent: 'border-l-warning',
        dot: 'bg-warning',
    },

    danger: {
        icon: 'text-destructive',
        iconBackground: 'bg-destructive/10 ring-destructive/10',
        accent: 'border-l-destructive',
        dot: 'bg-destructive',
    },

    info: {
        icon: 'text-primary',
        iconBackground: 'bg-primary/10 ring-primary/10',
        accent: 'border-l-primary',
        dot: 'bg-primary',
    },
};

/**
 * Which way the number went. Arrow and wording only — no colour.
 *
 * The two used to be one table: `up` meant an up arrow *and* green, `down`
 * meant a down arrow *and* red. That reads correctly for revenue and signups
 * and is exactly backwards for cost, churn, error rate and downtime — a
 * rising error rate was reported as good news, in green, and the worse it got
 * the greener it looked.
 */
const DIRECTION: Record<
    StatTrend['direction'],
    { icon: typeof ArrowUpRight; label: string }
> = {
    up: { icon: ArrowUpRight, label: 'widgets.increased' },
    down: { icon: ArrowDownRight, label: 'widgets.decreased' },
    neutral: { icon: ArrowRight, label: 'widgets.unchanged' },
};

/**
 * What the movement means. Colour and wording — no arrow.
 *
 * Semantic tokens rather than the literal emerald/red the direction table
 * carried: this *is* the success/destructive vocabulary UI-1 introduced, used
 * for the one thing in this widget that genuinely means good or bad.
 *
 * `neutral` is the default and is the whole point — a figure whose meaning
 * nobody stated is a figure whose meaning is not known.
 */
const SENTIMENT: Record<
    StatSentiment,
    { text: string; background: string; label: string | null }
> = {
    positive: {
        text: 'text-success',
        background: 'bg-success/10 ring-success/20',
        label: 'widgets.trend_positive',
    },
    negative: {
        text: 'text-destructive',
        background: 'bg-destructive/10 ring-destructive/20',
        label: 'widgets.trend_negative',
    },
    neutral: {
        text: 'text-muted-foreground',
        background: 'bg-muted/60 ring-border/60',
        label: null,
    },
};

function sentimentOf(trend: StatTrend): StatSentiment {
    // A payload serialized before sentiment existed says nothing, and
    // "nothing" is neutral rather than "whatever the arrow points at".
    return trend.sentiment ?? 'neutral';
}

/**
 * What the badge is called.
 *
 * The direction always: "Increased 12.4%" is true whatever it means. The
 * meaning only when one was stated — a neutral trend describes itself and
 * claims nothing further.
 */
function trendLabel(trend: StatTrend): string {
    const direction = t(DIRECTION[trend.direction].label);
    const meaning = SENTIMENT[sentimentOf(trend)].label;

    return meaning === null
        ? `${direction} ${trend.value}%`
        : `${direction} ${trend.value}% — ${t(meaning)}`;
}

const resolvedStats = computed(() =>
    props.stats.map((stat) => ({
        ...stat,
        url: safeUrl(stat.url),
        resolvedIcon: stat.icon ? resolveIcon(stat.icon) : undefined,
    })),
);

/**
 * The sparkline under a figure.
 *
 * A single number says nothing about whether it is a good week; the shape of
 * the last few periods is the context it is missing. Drawn inline rather than
 * through the chart widget: this is a decoration on a figure, not a chart
 * somebody reads values off.
 */
function sparkline(values: number[]): string {
    if (values.length < 2) {
        return '';
    }

    const min = Math.min(...values);
    const max = Math.max(...values);
    const range = max - min || 1;
    const step = 100 / (values.length - 1);

    return values
        .map((value, index) => {
            const x = index * step;
            const y = 24 - ((value - min) / range) * 24;

            return `${index === 0 ? 'M' : 'L'}${x.toFixed(2)},${y.toFixed(2)}`;
        })
        .join(' ');
}
</script>

<template>
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
        <!--
            A stat with a URL is a link to what it counts. `Link` rather than
            an anchor so it is an Inertia navigation like every other, and the
            destination authorizes for itself when it is followed.
        -->
        <component
            :is="stat.url ? Link : Card"
            v-for="stat in resolvedStats"
            :key="stat.label"
            :href="stat.url ?? undefined"
            :class="[
                'group relative block min-w-0 overflow-hidden rounded-lg border border-border/70 bg-background/70 p-4 shadow-xs transition-all hover:-translate-y-0.5 hover:border-border hover:bg-background hover:shadow-sm',
                COLOR_CLASSES[stat.color].accent,
                stat.url
                    ? 'cursor-pointer focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none'
                    : '',
            ]"
        >
            <div class="flex h-full min-w-0 flex-col">
                <!-- Top -->
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0 space-y-1">
                        <p
                            class="truncate text-sm leading-5 font-medium text-muted-foreground"
                        >
                            {{ stat.label }}
                        </p>
                    </div>

                    <div
                        v-if="stat.resolvedIcon"
                        class="flex size-9 shrink-0 items-center justify-center rounded-lg ring-1 ring-inset transition-transform group-hover:scale-105"
                        :class="[
                            COLOR_CLASSES[stat.color].iconBackground,
                            COLOR_CLASSES[stat.color].icon,
                        ]"
                    >
                        <component
                            :is="stat.resolvedIcon"
                            class="size-4"
                            :stroke-width="1.8"
                        />
                    </div>
                </div>

                <!-- Metric -->
                <div
                    class="mt-5 flex min-w-0 flex-wrap items-end gap-x-3 gap-y-2"
                >
                    <p
                        class="min-w-0 wrap-break-word text-3xl leading-none font-semibold tracking-tight text-foreground tabular-nums"
                    >
                        {{ stat.display }}
                    </p>

                    <!-- Trend -->
                    <div
                        v-if="stat.trend"
                        class="inline-flex h-6 shrink-0 items-center gap-1 rounded-md px-1.5 text-xs leading-none font-medium tabular-nums ring-1 ring-inset"
                        :class="[
                            SENTIMENT[sentimentOf(stat.trend)].text,
                            SENTIMENT[sentimentOf(stat.trend)].background,
                        ]"
                        :aria-label="trendLabel(stat.trend)"
                    >
                        <component
                            :is="DIRECTION[stat.trend.direction].icon"
                            class="size-3"
                            :stroke-width="2"
                        />

                        <span>{{ stat.trend.value }}%</span>
                    </div>
                </div>

                <!-- Sparkline -->
                <svg
                    v-if="stat.chart.length > 1"
                    class="mt-4 h-7 w-full"
                    viewBox="0 0 100 24"
                    preserveAspectRatio="none"
                    aria-hidden="true"
                >
                    <line
                        x1="0"
                        x2="100"
                        y1="23.5"
                        y2="23.5"
                        stroke="currentColor"
                        stroke-width="1"
                        class="text-border/60"
                        vector-effect="non-scaling-stroke"
                    />
                    <path
                        :d="sparkline(stat.chart)"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.5"
                        stroke-linejoin="round"
                        stroke-linecap="round"
                        vector-effect="non-scaling-stroke"
                        :class="COLOR_CLASSES[stat.color].icon"
                    />
                </svg>

                <!-- Description -->
                <div
                    v-if="stat.description"
                    class="mt-4 flex items-start gap-2 border-t border-border/60 pt-3"
                >
                    <span
                        class="mt-1.5 size-1.5 shrink-0 rounded-full"
                        :class="COLOR_CLASSES[stat.color].dot"
                    />

                    <p
                        class="line-clamp-2 text-xs leading-5 text-muted-foreground"
                    >
                        {{ stat.description }}
                    </p>
                </div>
            </div>
        </component>
    </div>
</template>
