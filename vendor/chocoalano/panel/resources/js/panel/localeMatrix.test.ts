/**
 * @vitest-environment happy-dom
 */
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * The panel speaks both of the languages it ships with.
 *
 * Key parity proves the dictionaries have the same shape and placeholder
 * parity proves the strings carry the same tokens. Neither proves a component
 * actually *reads* them: a control whose label is a literal passes both while
 * showing English to an Indonesian reader.
 *
 * These mount the real components against each dictionary in turn and read
 * what comes out. Every test sets its own locale explicitly — a test that
 * inherited one from whatever ran before it would pass or fail depending on
 * file order, which is the kind of green nobody should trust.
 */
const locale = { current: 'en' as 'en' | 'id' };

const DICTIONARIES = {
    en: {
        tables: {
            add_condition: 'Add condition',
            column: 'Column',
            condition: 'Condition',
            no_queryable_columns: 'No columns available to filter by.',
            max_conditions: 'Up to :count conditions.',
            remove_rule: 'Remove rule :number',
            rule_value: 'Value for :rule',
            rule: 'rule',
        },
        forms: {
            pick_a_date: 'Pick a date',
            clear_date: 'Clear date',
            clear_time: 'Clear time',
            hour: 'Hour',
            minute: 'Minute',
            second: 'Second',
            date: 'Date',
            time: 'Time',
            date_and_time: 'Date and time',
            color_for_field: ':field colour',
            remove_file: 'Remove :file',
            remove: 'Remove',
            uploads_unavailable: 'This form cannot store files.',
        },
        ui: {
            close: 'Close',
            loading: 'Loading',
            copy_command: 'Copy the :name command',
        },
    },
    id: {
        tables: {
            add_condition: 'Tambah kondisi',
            column: 'Kolom',
            condition: 'Kondisi',
            no_queryable_columns:
                'Tidak ada kolom yang tersedia untuk difilter.',
            max_conditions: 'Maksimal :count kondisi.',
            remove_rule: 'Hapus aturan :number',
            rule_value: 'Nilai untuk :rule',
            rule: 'aturan',
        },
        forms: {
            pick_a_date: 'Pilih tanggal',
            clear_date: 'Hapus tanggal',
            clear_time: 'Hapus waktu',
            hour: 'Jam',
            minute: 'Menit',
            second: 'Detik',
            date: 'Tanggal',
            time: 'Waktu',
            date_and_time: 'Tanggal dan waktu',
            color_for_field: 'Warna :field',
            remove_file: 'Hapus :file',
            remove: 'Hapus',
            uploads_unavailable: 'Formulir ini tidak dapat menyimpan berkas.',
        },
        ui: {
            close: 'Tutup',
            loading: 'Memuat',
            copy_command: 'Salin perintah :name',
        },
    },
} as const;

vi.mock('@inertiajs/vue3', () => ({
    router: {
        visit: vi.fn(),
        post: vi.fn(),
        reload: vi.fn(),
        on: () => () => {},
    },
    usePage: () => ({
        props: {
            translations: DICTIONARIES[locale.current],
            locale: locale.current,
        },
        url: '/',
        component: '',
        version: null,
    }),
    Link: { name: 'Link', template: '<a><slot /></a>' },
}));

const { default: DataTableQueryBuilder } =
    await import('@/panel/tables/DataTableQueryBuilder.vue');
const { default: PanelTimePicker } =
    await import('@/panel/components/PanelTimePicker.vue');
const { default: PanelDatePicker } =
    await import('@/panel/components/PanelDatePicker.vue');
const { default: DataTableIntro } =
    await import('@/panel/tables/DataTableIntro.vue');
const { default: ColorPickerField } =
    await import('@/panel/forms/fields/ColorPickerField.vue');
const { default: FileUploadField } =
    await import('@/panel/forms/fields/FileUploadField.vue');

const mounted: Array<{ unmount: () => void }> = [];

/** Every test states its own language; none inherits one. */
beforeEach(() => {
    locale.current = 'en';
});

afterEach(() => {
    while (mounted.length > 0) {
        mounted.pop()?.unmount();
    }

    document.body.innerHTML = '';
    locale.current = 'en';
});

function keep<T extends { unmount: () => void }>(wrapper: T): T {
    mounted.push(wrapper);

    return wrapper;
}

function speak(next: 'en' | 'id'): void {
    locale.current = next;
}

/** Every accessible name currently in the document. */
function names(): string[] {
    return [...document.querySelectorAll('[aria-label]')].map(
        (element) => element.getAttribute('aria-label') ?? '',
    );
}

const FILTER = {
    name: 'advanced',
    label: 'Advanced',
    type: 'query_builder',
    maxRules: 5,
    constraints: [
        {
            name: 'name',
            label: 'Name',
            input: 'text',
            operators: [{ value: 'equals', label: 'is', needsValue: true }],
        },
    ],
};

function queryBuilder(visibleColumns: string[] = ['name']) {
    return keep(
        mount(DataTableQueryBuilder, {
            attachTo: document.body,
            props: {
                filter: FILTER as never,
                rules: [],
                columnNames: ['name'],
                visibleColumns,
            },
        }),
    );
}

/*
 * I6 — the query builder
 */

describe('I6 — query builder', () => {
    it('speaks English', () => {
        speak('en');

        expect(queryBuilder().text()).toContain('Add condition');
    });

    it('speaks Indonesian', () => {
        speak('id');

        const wrapper = queryBuilder();

        expect(wrapper.text()).toContain('Tambah kondisi');
        expect(wrapper.text()).not.toContain('Add condition');
    });

    it('translates the empty state in both', () => {
        speak('en');
        expect(queryBuilder([]).text()).toContain(
            'No columns available to filter by.',
        );

        document.body.innerHTML = '';
        speak('id');
        expect(queryBuilder([]).text()).toContain(
            'Tidak ada kolom yang tersedia untuk difilter.',
        );
    });

    it('translates the rule ceiling with its placeholder filled', () => {
        speak('id');

        const wrapper = keep(
            mount(DataTableQueryBuilder, {
                attachTo: document.body,
                props: {
                    filter: FILTER as never,
                    rules: Array.from({ length: 5 }, () => ({
                        column: 'name',
                        operator: 'equals',
                        value: 'x',
                    })),
                    columnNames: ['name'],
                    visibleColumns: ['name'],
                },
            }),
        );

        // The placeholder is the reason placeholder parity is guarded: a
        // translation that dropped `:count` would render the literal token.
        expect(wrapper.text()).toContain('Maksimal 5 kondisi.');
    });
});

/*
 * I7 — the temporal controls
 */

describe('I7 — temporal', () => {
    it('names the time parts in English', () => {
        speak('en');
        keep(
            mount(PanelTimePicker, {
                attachTo: document.body,
                props: { modelValue: '09:05', seconds: true },
            }),
        );

        expect(names()).toEqual(
            expect.arrayContaining(['Hour', 'Minute', 'Second']),
        );
    });

    it('names the time parts in Indonesian', () => {
        speak('id');
        keep(
            mount(PanelTimePicker, {
                attachTo: document.body,
                props: { modelValue: '09:05', seconds: true },
            }),
        );

        expect(names()).toEqual(
            expect.arrayContaining(['Jam', 'Menit', 'Detik']),
        );
    });

    it('translates the date picker placeholder and clear', () => {
        speak('id');
        keep(
            mount(PanelDatePicker, {
                attachTo: document.body,
                props: { modelValue: null },
            }),
        );

        expect(document.body.textContent).toContain('Pilih tanggal');

        document.body.innerHTML = '';
        speak('en');
        keep(
            mount(PanelDatePicker, {
                attachTo: document.body,
                props: { modelValue: null },
            }),
        );

        expect(document.body.textContent).toContain('Pick a date');
    });

    it('translates the clear control', () => {
        speak('id');
        keep(
            mount(PanelTimePicker, {
                attachTo: document.body,
                props: { modelValue: '09:05' },
            }),
        );

        expect(names()).toContain('Hapus waktu');
    });
});

/*
 * I8 — a field that had a hardcoded label until this session
 */

describe('I8 — form controls', () => {
    const field = {
        component: 'field',
        type: 'color_picker',
        name: 'brand',
        label: 'Brand',
        required: false,
        disabled: false,
        helperText: null,
        swatches: [],
    };

    it('translates the colour control in English', () => {
        speak('en');
        keep(
            mount(ColorPickerField, {
                attachTo: document.body,
                props: { field: field as never, modelValue: '#ff0000' },
            }),
        );

        expect(names()).toContain('Brand colour');
    });

    it('translates the colour control in Indonesian', () => {
        speak('id');
        keep(
            mount(ColorPickerField, {
                attachTo: document.body,
                props: { field: field as never, modelValue: '#ff0000' },
            }),
        );

        // Until this session this read "Brand colour" in every language.
        expect(names()).toContain('Warna Brand');
    });
});

/*
 * I10 — the table's own surrounding UI
 */

describe('I10 — table description and callouts', () => {
    const callout = {
        component: 'callout',
        body: 'Payroll period is locked.',
        heading: null,
        tone: 'warning',
        icon: null,
        schema: [],
    };

    function intro() {
        return keep(
            mount(DataTableIntro, {
                attachTo: document.body,
                props: {
                    description: 'Excludes archived records.',
                    callouts: [callout as never],
                },
            }),
        );
    }

    it('renders developer copy verbatim in English', () => {
        speak('en');

        expect(intro().text()).toContain('Excludes archived records.');
    });

    it('renders developer copy verbatim in Indonesian too', () => {
        speak('id');

        // The package translates its own copy, not yours. A description and a
        // callout body are the application's words and are shown as given —
        // translating them would be the package guessing at somebody's data.
        const wrapper = intro();

        expect(wrapper.text()).toContain('Excludes archived records.');
        expect(wrapper.text()).toContain('Payroll period is locked.');
    });

    it('adds no package-owned English chrome around them', () => {
        speak('id');

        const text = intro().text();

        // Nothing the package contributes here is a word, so there is nothing
        // to leak. This asserts that stays true.
        expect(
            text
                .replace('Excludes archived records.', '')
                .replace('Payroll period is locked.', '')
                .trim(),
        ).toBe('');
    });
});

/*
 * I9 — upload
 */

describe('I9 — upload', () => {
    const field = {
        component: 'field',
        type: 'file_upload',
        name: 'attachment',
        label: 'Attachment',
        required: false,
        disabled: false,
        helperText: null,
        multiple: false,
        maxSize: 2048,
        maxFiles: null,
        acceptedTypes: [],
        image: false,
        previewBase: null,
    };

    function upload() {
        return keep(
            mount(FileUploadField, {
                attachTo: document.body,
                props: {
                    field: field as never,
                    modelValue: 'invoices/march.pdf',
                },
            }),
        );
    }

    it('names the remove control in English', () => {
        speak('en');
        upload();

        // Mounted rather than asserted from the dictionary: key parity proves
        // both locales define `remove_file`, and says nothing about whether
        // this component reads it. Until this test, that was the gap.
        expect(names()).toContain('Remove march.pdf');
    });

    it('names the remove control in Indonesian', () => {
        speak('id');
        upload();

        expect(names()).toContain('Hapus march.pdf');
    });

    it('labels the remove button in both', () => {
        speak('en');
        expect(upload().text()).toContain('Remove');

        document.body.innerHTML = '';
        speak('id');
        expect(upload().text()).toContain('Hapus');
    });
});
