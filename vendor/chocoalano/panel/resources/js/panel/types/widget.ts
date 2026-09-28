import type { FormDefinition } from '@/panel/types/form';
import type {
    ColumnDefinition,
    PaginationMeta,
    TableRow,
    TableState,
} from '@/panel/types/table';

/**
 * Mirrors the PHP widget classes.
 *
 * A discriminated union on `type`, so the renderer's exhaustive switch turns
 * a new PHP widget type without a Vue renderer into a compile error.
 */
export type WidgetType = 'stats' | 'table' | 'chart' | 'custom';

export type StatColor = 'default' | 'success' | 'warning' | 'danger' | 'info';

export type SpanValue = 1 | 2 | 3 | 4 | 'full';

export type Breakpoint = 'default' | 'md' | 'lg' | 'xl';

export type ColumnSpan = Record<Breakpoint, SpanValue>;

/**
 * What a movement means, which is not which way it went.
 *
 * `direction` is arithmetic; `sentiment` is a statement the widget author
 * made. Revenue rising and error rate rising are the same direction and
 * opposite news, and nothing about `+8%` distinguishes them.
 *
 * Optional on the wire: a payload serialized before this existed has no
 * `sentiment`, and is read as `neutral`.
 */
export type StatSentiment = 'positive' | 'negative' | 'neutral';

export interface StatTrend {
    direction: 'up' | 'down' | 'neutral';
    value: number;
    sentiment?: StatSentiment;
}

export interface StatDefinition {
    label: string;
    value: string | number;
    /** The figure as it should be read — grouped, prefixed, suffixed. */
    display: string;
    description: string | null;
    icon: string | null;
    color: StatColor;
    trend: StatTrend | null;
    /** A sparkline under the figure; empty when the stat declares none. */
    chart: number[];
    url: string | null;
}

/**
 * How a chart draws. A closed set of settings rather than a configuration
 * tree: the renderer was compiled in, and what crosses is a description.
 */
export interface ChartOptions {
    legend: boolean;
    grid: boolean;
    stacked: boolean;
    filled: boolean;
    curved: boolean;
    labels: boolean;
    min: number | null;
    max: number | null;
    prefix: string | null;
    suffix: string | null;
}

export type ChartVariant = 'bar' | 'line' | 'area' | 'doughnut';

export interface ChartSeriesDefinition {
    label: string;
    values: number[];
    color: StatColor;
}

/** A form a widget is filtered by, with the values it currently holds. */
export interface WidgetFilterDefinition {
    inModal: boolean;
    form: FormDefinition;
}

interface BaseWidgetDefinition {
    id: string;
    sort: number;
    columnSpan: ColumnSpan;
    /** Lazy widgets arrive with null data and fill in from `widgetData`. */
    lazy: boolean;
    heading: string | null;
    description: string | null;
    /** Seconds between refreshes, or null for a widget that does not poll. */
    polling: number | null;
    filters: WidgetFilterDefinition | null;
}

export interface StatsWidgetDefinition extends BaseWidgetDefinition {
    type: 'stats';
    data: { stats: StatDefinition[] } | null;
}

export interface TableWidgetDefinition extends BaseWidgetDefinition {
    type: 'table';
    data: {
        columns: ColumnDefinition[];
        rows: TableRow[];
        emptyMessage: string;
        state: TableState;
        pagination: PaginationMeta;
        /** Where this widget's table state lives in the query string. */
        namespace: string;
        searchable: boolean;
    } | null;
}

export interface ChartWidgetDefinition extends BaseWidgetDefinition {
    type: 'chart';
    data: {
        variant: ChartVariant;
        labels: string[];
        series: ChartSeriesDefinition[];
        options: ChartOptions;
        maxHeight: number;
    } | null;
}

export interface CustomWidgetDefinition extends BaseWidgetDefinition {
    type: 'custom';
    component: string;
    data: Record<string, unknown> | null;
}

export type WidgetDefinition =
    | StatsWidgetDefinition
    | TableWidgetDefinition
    | ChartWidgetDefinition
    | CustomWidgetDefinition;

/** `{widgetId: data}` for the lazy widgets, delivered as a deferred prop. */
export type WidgetData = Record<string, unknown>;
