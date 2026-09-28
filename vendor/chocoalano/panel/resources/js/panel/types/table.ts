import type { BadgeColorName } from '@/panel/palette';
import type { ActionDefinition } from '@/panel/types/action';
import type { CalloutDefinition, FormDefinition } from '@/panel/types/form';

/**
 * Mirrors the PHP table schema.
 *
 * Column and filter definitions are discriminated unions on `type`, matching
 * the `ColumnType` and `FilterType` enums. Renderers switch on that
 * discriminant with an exhaustive check, so adding a PHP column type without
 * a Vue renderer is a compile error rather than a blank cell.
 */
/**
 * Logical rather than physical, so a right-to-left locale flips without every
 * table being rewritten.
 */
export type ColumnAlignment = 'start' | 'center' | 'end' | 'justify';

/** Defined with the palette itself, and re-exported so column definitions
 * read as one file. */
export type { BadgeColorName };

interface BaseColumnDefinition {
    name: string;
    label: string;
    sortable: boolean;
    searchable: boolean;
    /** Carries its own search box as well as feeding the table-wide one. */
    individuallySearchable: boolean;
    visible: boolean;
    toggleable: boolean;
    alignment: ColumnAlignment;
    /** Defaults to the cell alignment; stated when a header should differ. */
    headerAlignment: ColumnAlignment;
    /** Shown in place of an empty cell. */
    placeholder: string | null;
    headerTooltip: string | null;
    wrapHeader: boolean;
    /** A CSS length, applied inline — a Tailwind class cannot be built here. */
    width: string | null;
    /**
     * The edge this column stays pinned to while the table scrolls sideways,
     * or null for one that scrolls with everything else.
     *
     * A pinned column is drawn at that edge whatever position it was declared
     * in — see `DataTable`'s `visibleColumns` for why it has to be.
     */
    frozen: 'start' | 'end' | null;
}

export interface TextColumnDefinition extends BaseColumnDefinition {
    type: 'text';
    wrap: boolean;
    placeholder: string | null;
}

export interface NumberColumnDefinition extends BaseColumnDefinition {
    type: 'number';
}

export interface BadgeColumnDefinition extends BaseColumnDefinition {
    type: 'badge';
}

export interface BooleanColumnDefinition extends BaseColumnDefinition {
    type: 'boolean';
}

export interface DateColumnDefinition extends BaseColumnDefinition {
    type: 'date';
}

export interface DateTimeColumnDefinition extends BaseColumnDefinition {
    type: 'datetime';
}

export interface ImageColumnDefinition extends BaseColumnDefinition {
    type: 'image';
    circular: boolean;
    size: number;
}

export interface IconColumnDefinition extends BaseColumnDefinition {
    type: 'icon';
}

export interface ColorColumnDefinition extends BaseColumnDefinition {
    type: 'color';
    copyable: boolean;
}

/**
 * Drawn by one of this application's own components, resolved through a
 * build-time registry key like a custom widget.
 */
export interface CustomColumnDefinition extends BaseColumnDefinition {
    type: 'custom';
    component: string;
}

/**
 * A cell the user writes through. `editable` is always true on these — it is
 * what tells the renderer to draw a control rather than a value.
 */
interface BaseEditableColumnDefinition extends BaseColumnDefinition {
    editable: true;
}

export interface ToggleColumnDefinition extends BaseEditableColumnDefinition {
    type: 'toggle';
}

export interface CheckboxColumnDefinition extends BaseEditableColumnDefinition {
    type: 'checkbox';
}

export interface TextInputColumnDefinition extends BaseEditableColumnDefinition {
    type: 'text_input';
    inputType: 'text' | 'number';
    maxLength: number | null;
}

export interface SelectColumnDefinition extends BaseEditableColumnDefinition {
    type: 'select';
    options: FilterOption[];
}

export type ColumnDefinition =
    | TextColumnDefinition
    | NumberColumnDefinition
    | BadgeColumnDefinition
    | BooleanColumnDefinition
    | DateColumnDefinition
    | DateTimeColumnDefinition
    | ImageColumnDefinition
    | IconColumnDefinition
    | ColorColumnDefinition
    | CustomColumnDefinition
    | ToggleColumnDefinition
    | CheckboxColumnDefinition
    | TextInputColumnDefinition
    | SelectColumnDefinition;

export type TextCell = string | null;
export type NumberCell = { display: string; raw: number } | null;
export type BadgeCell = {
    value: string;
    label: string;
    color: BadgeColorName;
} | null;
export type BooleanCell = { value: boolean; label: string };
export type DateCell = { display: string; iso: string } | null;
export type ImageCell = { url: string | null; fallback: string; alt: string };
export type IconCell = {
    icon: string;
    color: BadgeColorName;
    /** The accessible name: an icon alone reads as an empty cell. */
    label: string;
} | null;
export type ColorCell = { color: string; label: string } | null;
/** A custom column's state is whatever its component was written to take. */
export type CustomCell = unknown;

/**
 * An editable cell carries its own disabled state, because it is answered per
 * record: a cell can be read-only for some rows and not others.
 */
export type EditableCell = {
    value: string | boolean;
    label?: string;
    disabled: boolean;
};

export type CellValue =
    | TextCell
    | NumberCell
    | BadgeCell
    | BooleanCell
    | DateCell
    | ImageCell
    | IconCell
    | ColorCell
    | EditableCell
    | CustomCell;

export interface FilterOption {
    value: string;
    label: string;
}

interface BaseFilterDefinition {
    name: string;
    label: string;
    /** The value this filter starts on, or null when it has none. */
    default: FilterValue | null;
}

export interface SelectFilterDefinition extends BaseFilterDefinition {
    type: 'select';
    options: FilterOption[];
    placeholder: string | null;
}

export interface BooleanFilterDefinition extends BaseFilterDefinition {
    type: 'boolean';
    options: FilterOption[];
}

export interface DateFilterDefinition extends BaseFilterDefinition {
    type: 'date';
}

/** Three states, where the third is an answer rather than a cleared control. */
export interface TernaryFilterDefinition extends BaseFilterDefinition {
    type: 'ternary';
    options: FilterOption[];
    blankLabel: string;
}

/** A filter whose value is a whole form, rendered by the form renderer. */
export interface FormFilterDefinition extends BaseFilterDefinition {
    type: 'form';
    form: FormDefinition;
}

export interface ConstraintOperatorDefinition {
    value: string;
    label: string;
    /** `is blank` compares against nothing, so it renders no value input. */
    needsValue: boolean;
}

export interface ConstraintDefinition {
    name: string;
    label: string;
    input: 'text' | 'number' | 'date' | 'none';
    operators: ConstraintOperatorDefinition[];
}

export interface QueryBuilderFilterDefinition extends BaseFilterDefinition {
    type: 'query_builder';
    constraints: ConstraintDefinition[];
    maxRules: number;
}

export type FilterDefinition =
    | SelectFilterDefinition
    | BooleanFilterDefinition
    | DateFilterDefinition
    | TernaryFilterDefinition
    | FormFilterDefinition
    | QueryBuilderFilterDefinition;

/** A date filter's value; a select, boolean, or ternary uses a plain string. */
export interface DateFilterValue {
    from?: string;
    to?: string;
}

/** One rule of a query-builder filter. */
export interface QueryBuilderRule {
    column: string;
    operator: string;
    value?: string | number | null;
}

/** A form filter's value is its form's data, keyed by field name. */
export type FormFilterValue = Record<string, string | number | boolean | null>;

export type FilterValue =
    string | DateFilterValue | FormFilterValue | QueryBuilderRule[];

export interface TableEmptyState {
    heading: string;
    description: string | null;
    icon: string | null;
    /**
     * A component that replaces the whole empty state, resolved through a
     * build-time registry like a custom column.
     */
    component: string | null;
    /**
     * An empty table is where an action is most useful and least likely to be
     * found, because every other affordance is about rows.
     */
    actions: ActionDefinition[];
}

export type RecordActionsPosition =
    'after_columns' | 'before_columns' | 'after_cells';

/** Which renderer draws the records. */
export type TableLayout = 'table' | 'grid';

/**
 * Which of the table's columns fill which slot on a card.
 *
 * Column *names*, never definitions — the definitions are already in
 * `TableDefinition.columns`, and sending them twice would be two places for
 * the same column to disagree with itself. The server resolves the slots
 * nobody declared; this is the answer, not the rule.
 */
export interface CardFaceDefinition {
    /** Cards per row. Only ever a count `panel/lib/grid` has a class for. */
    columns: number;
    image: string | null;
    title: string | null;
    /** Never inferred: guessing which column is a subtitle is guessing. */
    description: string | null;
    badges: string[];
    details: string[];
}

/**
 * How the filter bar behaves. The server decides, because how expensive a
 * request is, is the server's knowledge.
 */
export interface FilterBehaviour {
    /** Hold changes until an apply action is used. */
    deferred: boolean;
    triggerLabel: string;
    triggerIcon: string | null;
    applyLabel: string;
    resetLabel: string;
    showReset: boolean;
}

/** How the column manager behaves, decided by the server like everything else. */
export interface ColumnManager {
    /** Whether the user may drag columns into a different order. */
    reorderable: boolean;
    /** Hold changes until an apply action is used. */
    deferred: boolean;
    triggerLabel: string;
    triggerIcon: string | null;
    resetLabel: string;
    showReset: boolean;
    /** Open as a dialog rather than a popover, for a long column list. */
    modal: boolean;
    /** The columns that may be hidden; the rest always stay. */
    toggleable: string[];
}

/** One way a table can band its rows. */
export interface GroupDefinition {
    name: string;
    label: string;
}

export interface TableDefinition {
    bulkActions: ActionDefinition[];
    /** Act on the table rather than a row, so they resolve without a record. */
    headerActions: ActionDefinition[];
    toolbarActions: ActionDefinition[];
    recordActions: { position: RecordActionsPosition; label: string | null };
    groups: GroupDefinition[];
    defaultGroup: string | null;
    columns: ColumnDefinition[];
    columnManager: ColumnManager;
    /** A sentence about the table, shown before its rows. Null for none. */
    description: string | null;
    /** Notices shown above the table, in declaration order. */
    callouts: CalloutDefinition[];
    filters: FilterDefinition[];
    filterBehaviour: FilterBehaviour;
    searchable: boolean;
    searchPlaceholder: string;
    /** Milliseconds to wait after a keystroke; 0 asks immediately. */
    searchDebounce: number;
    /** Ask when the field loses focus rather than while typing. */
    searchOnBlur: boolean;
    /** Names of the columns that carry their own search box. */
    individualSearchColumns: string[];
    selectable: boolean;
    /** Rows can be dragged into a new order, persisted by the server. */
    reorderable: boolean;
    /**
     * Which structural cells take part in freezing.
     *
     * Answers rather than the rule that produced them: `start` is true when
     * any column is pinned to the leading edge, and the reorder handle and
     * selection checkbox are pinned with it — they sit to the left of every
     * data column, and letting them scroll while a column beside them stays
     * put would be two things disagreeing about where the row begins.
     */
    frozen: {
        start: boolean;
        actions: boolean;
    };
    perPageOptions: number[];
    defaultPerPage: number;
    defaultSort: {
        column: string;
        direction: SortDirection;
        /** Names the fallback ordering, for a UI that shows what is applied. */
        label: string | null;
    } | null;
    /**
     * The layouts this table can be drawn in, always with `table` first.
     *
     * The answer rather than the rule: a reorderable table offers only the
     * table however its card face is declared, and that is decided on the
     * server so nothing here has to re-derive it. A single entry is what makes
     * the layout toggle render nothing at all.
     */
    layouts: TableLayout[];
    /** Null for a table that declared no card face. */
    cards: CardFaceDefinition | null;
    emptyState: TableEmptyState;
}

export type SortDirection = 'asc' | 'desc';

/**
 * The state the server actually applied, not the state that was requested.
 * Rendering controls from this is what keeps a rejected sort or filter from
 * appearing active.
 */
export interface TableState {
    search: string | null;
    sort: string | null;
    direction: SortDirection;
    perPage: number;
    filters: Record<string, FilterValue>;
    /**
     * What is narrowing the table, said in words. Built on the server because
     * only a filter knows what its value means: `1` is "Verified", not "1".
     */
    filterIndicators: Array<{ name: string; label: string }>;
    /** Per-column terms the server accepted, keyed by column name. */
    columnSearches: Record<string, string>;
    /**
     * Which columns are shown and in what order, already validated: an
     * unknown name is dropped and a non-toggleable column stays visible.
     */
    columns: { visible: string[]; order: string[] };
    /** The band the table is arranged by, or null when it is not grouped. */
    group: string | null;
    /**
     * The layout being drawn, already validated against `table.layouts`.
     *
     * The resolved answer rather than the request, so a rejected `?layout=`
     * leaves the toggle pointing at what is actually on screen.
     */
    layout: TableLayout;
}

/**
 * The per-row extras a shared column definition cannot carry: a tooltip, a
 * link, or attributes that depend on the record.
 *
 * Beside the cells rather than inside them, so a cell value stays exactly the
 * shape its guard narrows and a table using none of this pays nothing.
 */
export interface CellMeta {
    tooltip?: string;
    url?: string;
    attributes?: Record<string, string | number | boolean>;
    /**
     * An action the whole cell runs. Resolved per record, so a cell the user
     * may not act on simply has none.
     */
    action?: ActionDefinition;
}

/**
 * The band a row falls in, when the table is grouped.
 *
 * Per row rather than a grouped structure, because grouping is presentation:
 * the query still returns a page of rows, so a group can be split across two
 * pages exactly as any run of rows can.
 */
export interface RowGroup {
    key: string;
    title: string;
    description: string | null;
}

export interface TableRow {
    key: string | number;
    group: RowGroup | null;
    cells: Record<string, CellValue>;
    cellMeta: Record<string, CellMeta>;
    /** Resolved per row: an action the user may not run is simply absent. */
    actions: ActionDefinition[];
}

/**
 * One figure under a column. Formatted on the server like every other cell;
 * `raw` is there for anything that wants the number rather than the text.
 */
export interface SummaryFigure {
    name: string;
    label: string;
    value: string;
    raw: string | number | boolean | null;
    /** Describes the page rather than the whole filtered result. */
    perPage: boolean;
}

/** Figures keyed by column name; absent for a table that declares none. */
export type TableSummaries = Record<string, SummaryFigure[]>;

/** The same, per band, keyed by group key then column. */
export type TableGroupSummaries = Record<string, TableSummaries>;

export interface PaginationMeta {
    page: number;
    perPage: number;
    total: number;
    lastPage: number;
    from: number;
    to: number;
}

export interface ResourceMeta {
    slug: string;
    label: string;
    pluralLabel: string;
    indexUrl: string;
    /**
     * The parent record a nested resource is scoped to. The action endpoints
     * carry no parent segment, so the table has to send it with every action
     * it posts. Null for a resource that is not nested.
     */
    parentKey: string | number | null;
}

/**
 * One filter tab above the table. `active` is the server's decision, like
 * every other piece of table state.
 */
export interface TableTab {
    key: string;
    label: string;
    icon: string | null;
    badge: string | number | null;
    active: boolean;
}
