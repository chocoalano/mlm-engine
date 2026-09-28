/**
 * @vitest-environment happy-dom
 */
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type {
    ColumnDefinition,
    TableDefinition,
    TableRow,
    TableState,
} from '@/panel/types/table';

/**
 * Whether every row of the table agrees about what a column is.
 *
 * Four rows are drawn horizontally — the header, the column-search header, the
 * body, and the summary footer — and each of them used to decide for itself
 * which structural cells to place. Two of them decided differently: the search
 * row and the footer rendered the actions cell unconditionally at the *end*.
 * With `recordActions.position = before_columns` the header therefore had three
 * leading cells and the search row two, so every search box sat under the wrong
 * column and a stray cell hung off the right.
 *
 * These assert the rendered `<th>` and `<td>` sequence, not a helper array:
 * the defect was that the markup disagreed, and only the markup can show it
 * agreeing.
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
            panel: { id: 'admin', path: 'admin' },
            translations: {
                tables: {
                    actions: 'Actions',
                    reorder: 'Reorder',
                    select_all_rows: 'Select all rows',
                    select_row: 'Select :record',
                    sort_by: 'Sort by :column',
                    search_column: 'Search :column',
                    move_up: 'Move up',
                    move_down: 'Move down',
                    reorder_row:
                        'Reorder :record, position :position of :total',
                    reorder_announcement:
                        ':record moved to position :position of :total',
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

const { default: DataTable } = await import('@/panel/tables/DataTable.vue');

const mounted: Array<{ unmount: () => void }> = [];

afterEach(() => {
    while (mounted.length > 0) {
        mounted.pop()?.unmount();
    }

    document.body.innerHTML = '';
});

function column(overrides: Partial<ColumnDefinition> = {}): ColumnDefinition {
    return {
        name: 'name',
        label: 'Name',
        type: 'text',
        wrap: false,
        sortable: false,
        searchable: false,
        individuallySearchable: true,
        visible: true,
        toggleable: true,
        alignment: 'start',
        headerAlignment: 'start',
        placeholder: null,
        headerTooltip: null,
        wrapHeader: false,
        width: null,
        frozen: null,
        ...overrides,
    } as ColumnDefinition;
}

function table(overrides: Record<string, unknown> = {}): TableDefinition {
    return {
        bulkActions: [],
        headerActions: [],
        toolbarActions: [],
        recordActions: { position: 'after_columns', label: null },
        groups: [],
        defaultGroup: null,
        columns: [column(), column({ name: 'status', label: 'Status' })],
        columnManager: { toggleable: false, reorderable: false },
        filters: [],
        filterBehaviour: 'live',
        searchable: false,
        searchPlaceholder: 'Search',
        searchDebounce: 0,
        searchOnBlur: false,
        individualSearchColumns: ['name', 'status'],
        selectable: false,
        reorderable: false,
        frozen: { start: false, actions: false },
        emptyState: { heading: 'Nothing', description: null, icon: null },
        layouts: ['table'],
        striped: false,
        ...overrides,
    } as unknown as TableDefinition;
}

function state(overrides: Partial<TableState> = {}): TableState {
    return {
        search: null,
        sort: null,
        direction: 'asc',
        perPage: 10,
        filters: {},
        filterIndicators: [],
        columnSearches: {},
        columns: { visible: ['name', 'status'], order: ['name', 'status'] },
        group: null,
        layout: 'table',
        ...overrides,
    } as TableState;
}

function rows(): TableRow[] {
    return [
        {
            key: 1,
            group: null,
            cells: { name: 'Alice', status: 'Active' },
            cellMeta: {},
            actions: [],
        },
        {
            key: 2,
            group: null,
            cells: { name: 'Bob', status: 'Away' },
            cellMeta: {},
            actions: [],
        },
    ] as unknown as TableRow[];
}

function render(overrides: Record<string, unknown> = {}) {
    const wrapper = mount(DataTable, {
        attachTo: document.body,
        props: {
            table: table(),
            rows: rows(),
            state: state(),
            ...overrides,
        },
    });

    mounted.push(wrapper);

    return wrapper;
}

/**
 * A row's cells, named by what they are.
 *
 * Structural cells are recognised by their content rather than a test-only
 * attribute, so the assertion is about what somebody would see.
 */
function shape(row: Element): string[] {
    return [...row.children].map((cell) => {
        const text = cell.textContent?.trim() ?? '';

        // A checkbox renders no text of its own, so the control is what names
        // the cell rather than its content.
        if (cell.querySelector('[role="checkbox"]')) {
            return 'select';
        }

        if (text.startsWith('Reorder')) {
            return 'reorder';
        }

        if (text.startsWith('Select')) {
            return 'select';
        }

        if (text === 'Actions') {
            return 'actions';
        }

        if (cell.querySelector('input[type="search"]')) {
            return `search:${cell
                .querySelector('input')
                ?.getAttribute('aria-label')
                ?.replace('Search ', '')
                .toLowerCase()}`;
        }

        return text === '' ? 'empty' : text.toLowerCase();
    });
}

/**
 * Read from the document rather than the wrapper: the table is attached, and
 * what matters is the markup a browser would lay out.
 */
function headerRows(): Element[] {
    return [...document.querySelectorAll('thead tr')];
}

function bodyRow(): Element {
    return document.querySelector('tbody tr') as Element;
}

/*
 * T1 / T2 / T3
 */

describe('where the actions column sits', () => {
    it('agrees across header, search and body when actions come first', () => {
        render({
            table: table({
                recordActions: { position: 'before_columns', label: null },
            }),
            rows: rows().map((row) => ({
                ...row,
                actions: [{ name: 'edit', label: 'Edit', type: 'link' }],
            })),
        });

        const [header, search] = headerRows();

        // Three leading cells in the header and two in the search row is the
        // whole defect: every search box was under the wrong column.
        expect(shape(header)).toEqual(['actions', 'name', 'status']);
        expect(shape(search)).toEqual([
            'empty',
            'search:name',
            'search:status',
        ]);
        expect(shape(bodyRow())).toHaveLength(3);
    });

    it('agrees when actions come last', () => {
        render({
            rows: rows().map((row) => ({
                ...row,
                actions: [{ name: 'edit', label: 'Edit', type: 'link' }],
            })),
        });

        const [header, search] = headerRows();

        expect(shape(header)).toEqual(['name', 'status', 'actions']);
        expect(shape(search)).toEqual([
            'search:name',
            'search:status',
            'empty',
        ]);
        expect(shape(bodyRow())).toHaveLength(3);
    });

    it('reserves nothing when there are no actions', () => {
        render();

        const [header, search] = headerRows();

        expect(shape(header)).toEqual(['name', 'status']);
        expect(shape(search)).toEqual(['search:name', 'search:status']);
        expect(shape(bodyRow())).toHaveLength(2);
    });

    it('places reorder and select ahead of a leading actions cell', () => {
        render({
            table: table({
                selectable: true,
                reorderable: true,
                recordActions: { position: 'before_columns', label: null },
            }),
            rows: rows().map((row) => ({
                ...row,
                actions: [{ name: 'edit', label: 'Edit', type: 'link' }],
            })),
        });

        const [header, search] = headerRows();

        expect(shape(header)).toEqual([
            'reorder',
            'select',
            'actions',
            'name',
            'status',
        ]);
        expect(shape(search)).toEqual([
            'empty',
            'empty',
            'empty',
            'search:name',
            'search:status',
        ]);
        expect(shape(bodyRow())).toHaveLength(5);
    });
});

/*
 * T4 / T5 / T6 / T7
 */

describe('columns the user changed', () => {
    it('drops a hidden column from every row', () => {
        render({
            state: state({
                columns: { visible: ['status'], order: ['name', 'status'] },
            }),
        });

        const [header, search] = headerRows();

        expect(shape(header)).toEqual(['status']);
        expect(shape(search)).toEqual(['search:status']);
        expect(shape(bodyRow())).toHaveLength(1);
    });

    it('follows a reordered column in every row', () => {
        // `columns.visible` is the display order: the server validates the
        // request and sends the columns already arranged, so `order` records
        // the preference and `visible` is what is drawn.
        render({
            state: state({
                columns: {
                    visible: ['status', 'name'],
                    order: ['status', 'name'],
                },
            }),
        });

        const [header, search] = headerRows();

        expect(shape(header)).toEqual(['status', 'name']);
        // The search controls follow rather than staying in schema order.
        expect(shape(search)).toEqual(['search:status', 'search:name']);
    });

    it('keeps the footer aligned with the header', () => {
        render({
            table: table({
                recordActions: { position: 'before_columns', label: null },
                selectable: true,
            }),
            rows: rows().map((row) => ({
                ...row,
                actions: [{ name: 'edit', label: 'Edit', type: 'link' }],
            })),
            summaries: { status: [{ label: 'Total', value: '2' }] },
        });

        const [header] = headerRows();
        const footer = document.querySelector('tfoot tr') as Element;

        // The footer had the same defect as the search row, and for the same
        // reason: it drew its own actions cell at the end regardless.
        expect(footer.children).toHaveLength(header.children.length);
        expect(shape(footer).slice(0, 3)).toEqual(['empty', 'empty', 'empty']);
    });

    it('pins the same leading cells it draws when frozen', () => {
        render({
            table: table({
                selectable: true,
                frozen: { start: true, actions: false },
                recordActions: { position: 'before_columns', label: null },
            }),
            rows: rows().map((row) => ({
                ...row,
                actions: [{ name: 'edit', label: 'Edit', type: 'link' }],
            })),
        });

        const [header, search] = headerRows();

        expect(shape(header)).toEqual(['select', 'actions', 'name', 'status']);
        expect(shape(search)).toEqual([
            'empty',
            'empty',
            'search:name',
            'search:status',
        ]);
    });
});

/*
 * U15A — sort, said rather than drawn
 *
 * T8 / T9 / T10 / T11 / T12
 */

function headerCell(label: string): Element {
    return [...document.querySelectorAll('thead tr:first-child th')].find(
        (cell) => cell.textContent?.trim().startsWith(label),
    ) as Element;
}

describe('a sortable column', () => {
    it('says nothing about direction until it is sorted', () => {
        render({
            table: table({
                columns: [
                    column({ sortable: true }),
                    column({ name: 'status', label: 'Status', sortable: true }),
                ],
            }),
        });

        // `none` is a statement that this column takes part in sorting, which
        // is true and worth saying — the direction was previously carried by
        // an arrow icon and nothing else.
        expect(headerCell('Name').getAttribute('aria-sort')).toBe('none');
    });

    it('says ascending when it is', () => {
        render({
            table: table({ columns: [column({ sortable: true })] }),
            state: state({
                sort: 'name',
                direction: 'asc',
                columns: { visible: ['name'], order: ['name'] },
            }),
        });

        expect(headerCell('Name').getAttribute('aria-sort')).toBe('ascending');
    });

    it('says descending when it is', () => {
        render({
            table: table({ columns: [column({ sortable: true })] }),
            state: state({
                sort: 'name',
                direction: 'desc',
                columns: { visible: ['name'], order: ['name'] },
            }),
        });

        expect(headerCell('Name').getAttribute('aria-sort')).toBe('descending');
    });

    it('claims nothing on a column that cannot be sorted', () => {
        render();

        // `aria-sort="none"` on a non-sortable column would claim it takes
        // part in sorting. The attribute is absent instead.
        expect(headerCell('Name').getAttribute('aria-sort')).toBeNull();
    });

    it('still asks the table to sort when pressed', async () => {
        const wrapper = render({
            table: table({ columns: [column({ sortable: true })] }),
            state: state({ columns: { visible: ['name'], order: ['name'] } }),
        });

        const button = headerCell('Name').querySelector(
            'button',
        ) as HTMLElement;

        expect(button.getAttribute('aria-label')).toBe('Sort by Name');

        button.click();
        await flushPromises();

        expect(wrapper.emitted('sort')).toEqual([['name']]);
    });
});

/*
 * U15B — moving a row without a pointer
 *
 * T13 / T14 / T15 / T16 / T17 / T18 / T19 / T20
 */

function reorderTable() {
    return {
        table: table({ reorderable: true }),
        rows: [
            {
                key: 1,
                group: null,
                cells: { name: 'Alice', status: 'A' },
                cellMeta: {},
                actions: [],
            },
            {
                key: 2,
                group: null,
                cells: { name: 'Bob', status: 'B' },
                cellMeta: {},
                actions: [],
            },
            {
                key: 3,
                group: null,
                cells: { name: 'Cara', status: 'C' },
                cellMeta: {},
                actions: [],
            },
        ] as unknown as TableRow[],
    };
}

function handles(): HTMLElement[] {
    return [...document.querySelectorAll<HTMLElement>('[data-reorder-row]')];
}

async function openMenu(index: number) {
    handles()[index].click();
    await flushPromises();
}

function menuItem(label: string): HTMLElement | undefined {
    return [
        ...document.querySelectorAll<HTMLElement>('[role="menuitem"]'),
    ].find((item) => item.textContent?.trim() === label);
}

function announcement(): string {
    return document.querySelector('[role="status"]')?.textContent?.trim() ?? '';
}

describe('moving a row from the keyboard', () => {
    it('moves it down and reports where it went', async () => {
        const wrapper = render(reorderTable());

        await openMenu(1);
        menuItem('Move down')?.click();
        await flushPromises();

        // The same transport the drag path uses: one `reorder` emit carrying
        // the whole new order. There is no second endpoint.
        expect(wrapper.emitted('reorder')).toEqual([[[1, 3, 2]]]);
        expect(announcement()).toBe('Bob moved to position 3 of 3');
    });

    it('moves it up', async () => {
        const wrapper = render(reorderTable());

        await openMenu(1);
        menuItem('Move up')?.click();
        await flushPromises();

        expect(wrapper.emitted('reorder')).toEqual([[[2, 1, 3]]]);
        expect(announcement()).toBe('Bob moved to position 1 of 3');
    });

    it('offers no way up from the first row', async () => {
        render(reorderTable());

        await openMenu(0);

        // Absent rather than disabled: a disabled item is still an item to
        // arrow past, and moving the first row up is not a thing that can be
        // done rather than one temporarily unavailable.
        expect(menuItem('Move up')).toBeUndefined();
        expect(menuItem('Move down')).toBeDefined();
    });

    it('offers no way down from the last row', async () => {
        render(reorderTable());

        await openMenu(2);

        expect(menuItem('Move down')).toBeUndefined();
        expect(menuItem('Move up')).toBeDefined();
    });

    it('names the row rather than its key', async () => {
        render(reorderTable());

        // "Reorder row 4821" is an identifier, not a name. The first text cell
        // is what somebody reading the table would call the row.
        expect(handles()[1].getAttribute('aria-label')).toBe(
            'Reorder Bob, position 2 of 3',
        );
    });

    it('keeps the drag path intact', async () => {
        const wrapper = render(reorderTable());

        const handle = handles()[0];

        expect(handle.getAttribute('draggable')).toBe('true');

        handle.dispatchEvent(new Event('dragstart', { bubbles: true }));
        await flushPromises();

        const rows = [...document.querySelectorAll('tbody tr')];

        rows[2].dispatchEvent(new Event('drop', { bubbles: true }));
        await flushPromises();

        // Pointer reordering still produces the identical emit; the keyboard
        // is an additional path to the same operation, not a replacement.
        expect(wrapper.emitted('reorder')).toEqual([[[2, 3, 1]]]);
    });
});
