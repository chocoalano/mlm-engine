import type {
    CellValue,
    ColumnDefinition,
    PaginationMeta,
    TableRow,
    TableState,
} from '@/panel/types/table';
import type {
    ChartOptions,
    ChartSeriesDefinition,
    ChartVariant,
    StatColor,
    StatDefinition,
    StatTrend,
} from '@/panel/types/widget';

/**
 * A table widget's payload, with the table-builder state a widget only has
 * once it is paginated. The four may be null for a payload written before the
 * widget grew them.
 */
export interface TableWidgetPayload {
    columns: ColumnDefinition[];
    rows: TableRow[];
    emptyMessage: string;
    state: TableState | null;
    pagination: PaginationMeta | null;
    namespace: string | null;
    searchable: boolean;
}

export interface ChartWidgetPayload {
    variant: ChartVariant;
    labels: string[];
    series: ChartSeriesDefinition[];
    options: ChartOptions;
    maxHeight: number;
}

/**
 * Widget payloads arrive as JSON, and a lazy widget's payload arrives on a
 * later request than its definition. Narrowing here rather than asserting
 * means a shape mismatch degrades to a skeleton or an empty widget instead
 * of throwing inside the dashboard.
 */
function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

const COLORS: readonly StatColor[] = [
    'default',
    'success',
    'warning',
    'danger',
    'info',
];

function toColor(value: unknown): StatColor {
    return COLORS.find((candidate) => candidate === value) ?? 'default';
}

function toStat(value: unknown): StatDefinition | null {
    if (!isRecord(value) || typeof value.label !== 'string') {
        return null;
    }

    const trend: StatTrend | null =
        isRecord(value.trend) && typeof value.trend.value === 'number'
            ? {
                  direction:
                      value.trend.direction === 'up' ||
                      value.trend.direction === 'down'
                          ? value.trend.direction
                          : 'neutral',
                  value: value.trend.value,
              }
            : null;

    const raw =
        typeof value.value === 'string' || typeof value.value === 'number'
            ? value.value
            : '';

    return {
        label: value.label,
        value: raw,
        // Formatted on the server, because how a figure should be read is
        // part of what it means. Falls back to the raw value for a payload
        // written before the widget declared one.
        display:
            typeof value.display === 'string' ? value.display : String(raw),
        description:
            typeof value.description === 'string' ? value.description : null,
        icon: typeof value.icon === 'string' ? value.icon : null,
        color: toColor(value.color),
        trend,
        chart: Array.isArray(value.chart)
            ? value.chart.filter(
                  (point): point is number =>
                      typeof point === 'number' && Number.isFinite(point),
              )
            : [],
        url:
            typeof value.url === 'string' && value.url !== ''
                ? value.url
                : null,
    };
}

export function asStats(payload: unknown): StatDefinition[] | null {
    if (!isRecord(payload) || !Array.isArray(payload.stats)) {
        return null;
    }

    return payload.stats
        .map(toStat)
        .filter((stat): stat is StatDefinition => stat !== null);
}

function toColumn(value: unknown): ColumnDefinition | null {
    return isRecord(value) &&
        typeof value.name === 'string' &&
        typeof value.type === 'string'
        ? (value as unknown as ColumnDefinition)
        : null;
}

function toRow(value: unknown): TableRow | null {
    if (!isRecord(value) || !isRecord(value.cells)) {
        return null;
    }

    const key = value.key;

    if (typeof key !== 'string' && typeof key !== 'number') {
        return null;
    }

    // Cell values are narrowed again by the cell renderer, so this only has
    // to establish that the map exists.
    const cells: Record<string, CellValue> = {};

    for (const [name, cell] of Object.entries(value.cells)) {
        cells[name] = cell as CellValue;
    }

    return {
        key,
        cells,
        // A widget table has no per-cell extras and no grouping; the shape is
        // shared with a resource table, so the keys are present and empty
        // rather than absent.
        cellMeta: {},
        group: null,
        actions: Array.isArray(value.actions) ? value.actions : [],
    };
}

export function asTable(payload: unknown): TableWidgetPayload | null {
    if (
        !isRecord(payload) ||
        !Array.isArray(payload.columns) ||
        !Array.isArray(payload.rows)
    ) {
        return null;
    }

    return {
        columns: payload.columns
            .map(toColumn)
            .filter((column): column is ColumnDefinition => column !== null),
        rows: payload.rows
            .map(toRow)
            .filter((row): row is TableRow => row !== null),
        // Empty rather than a sentence: this module has no page and cannot
        // reach a translation. `TableWidget` fills it from `widgets.empty`.
        emptyMessage:
            typeof payload.emptyMessage === 'string'
                ? payload.emptyMessage
                : '',
        // The table builder's own shapes, so the widget renders through the
        // same controls a resource index does.
        state: isRecord(payload.state)
            ? (payload.state as unknown as TableState)
            : null,
        pagination: isRecord(payload.pagination)
            ? (payload.pagination as unknown as PaginationMeta)
            : null,
        namespace:
            typeof payload.namespace === 'string' ? payload.namespace : null,
        searchable: payload.searchable === true,
    };
}

function toSeries(value: unknown): ChartSeriesDefinition | null {
    if (
        !isRecord(value) ||
        typeof value.label !== 'string' ||
        !Array.isArray(value.values)
    ) {
        return null;
    }

    return {
        label: value.label,
        values: value.values.filter(
            (item): item is number => typeof item === 'number',
        ),
        color: toColor(value.color),
    };
}

const CHART_VARIANTS: readonly ChartVariant[] = [
    'bar',
    'line',
    'area',
    'doughnut',
];

const DEFAULT_CHART_OPTIONS: ChartOptions = {
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
};

/**
 * Every option, defaulted. A payload written before an option existed is
 * still a valid chart, and falling back per key is what keeps it one.
 */
function toChartOptions(value: unknown): ChartOptions {
    if (!isRecord(value)) {
        return DEFAULT_CHART_OPTIONS;
    }

    const flag = (key: keyof ChartOptions): boolean =>
        typeof value[key] === 'boolean'
            ? (value[key] as boolean)
            : (DEFAULT_CHART_OPTIONS[key] as boolean);

    const number = (key: keyof ChartOptions): number | null =>
        typeof value[key] === 'number' ? (value[key] as number) : null;

    const text = (key: keyof ChartOptions): string | null =>
        typeof value[key] === 'string' && value[key] !== ''
            ? (value[key] as string)
            : null;

    return {
        legend: flag('legend'),
        grid: flag('grid'),
        stacked: flag('stacked'),
        filled: flag('filled'),
        curved: flag('curved'),
        labels: flag('labels'),
        min: number('min'),
        max: number('max'),
        prefix: text('prefix'),
        suffix: text('suffix'),
    };
}

export function asChart(payload: unknown): ChartWidgetPayload | null {
    if (!isRecord(payload) || !Array.isArray(payload.series)) {
        return null;
    }

    return {
        variant:
            CHART_VARIANTS.find((candidate) => candidate === payload.variant) ??
            'bar',
        labels: Array.isArray(payload.labels)
            ? payload.labels.filter(
                  (label): label is string => typeof label === 'string',
              )
            : [],
        series: payload.series
            .map(toSeries)
            .filter(
                (series): series is ChartSeriesDefinition => series !== null,
            ),
        options: toChartOptions(payload.options),
        maxHeight:
            typeof payload.maxHeight === 'number' ? payload.maxHeight : 220,
    };
}

export function asCustomData(payload: unknown): Record<string, unknown> | null {
    return isRecord(payload) ? payload : null;
}
