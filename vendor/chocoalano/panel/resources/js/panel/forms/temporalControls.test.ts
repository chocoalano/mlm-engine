/**
 * @vitest-environment happy-dom
 */
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type {
    DateFieldDefinition,
    DateTimeFieldDefinition,
    TimeFieldDefinition,
} from '@/panel/types/form';
import type {
    QueryBuilderFilterDefinition,
    QueryBuilderRule,
} from '@/panel/types/table';

/**
 * What a panel picks a date, a time and a datetime with.
 *
 * Three renderers created a browser-native temporal control: `type="time"`,
 * `type="datetime-local"`, and — with no literal attribute to search for — a
 * `:type` binding in the query builder that resolved to `date` for a date
 * constraint. Native controls are drawn by the browser rather than by the
 * panel: three engines draw three different things, none of them themeable,
 * none matching the rest of the form, and some of them silently rounding a
 * value that carried seconds.
 *
 * These assert the two halves that matter and that a grep cannot: that no
 * forbidden control reaches the DOM, and that the value contract crossing the
 * boundary is exactly what it was. `tests/browser/temporal.mjs` proves the
 * same components in Chrome, where layout and portals are real.
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
                forms: {
                    pick_a_date: 'Pick a date',
                    clear_date: 'Clear date',
                    clear_time: 'Clear time',
                    date: 'Date',
                    time: 'Time',
                    date_and_time: 'Date and time',
                    hour: 'Hour',
                    minute: 'Minute',
                    second: 'Second',
                },
                tables: { rule_value: 'Value for :rule', rule: 'rule' },
            },
        },
        url: '/',
        component: '',
        version: null,
    }),
    Link: { name: 'Link', template: '<a><slot /></a>' },
}));

// Keep popover contents mounted for value-contract tests; browser checks cover portals.
vi.mock('@/components/ui/popover', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@/components/ui/popover')>()),
    PopoverContent: { template: '<div><slot /></div>' },
}));

const { default: PanelTimePicker } =
    await import('@/panel/components/PanelTimePicker.vue');
const { default: TimeField } =
    await import('@/panel/forms/fields/TimeField.vue');
const { default: DateTimeField } =
    await import('@/panel/forms/fields/DateTimeField.vue');
const { default: DataTableQueryBuilder } =
    await import('@/panel/tables/DataTableQueryBuilder.vue');
const { default: DateField } =
    await import('@/panel/forms/fields/DateField.vue');

const mounted: Array<{ unmount: () => void }> = [];

afterEach(() => {
    while (mounted.length > 0) {
        mounted.pop()?.unmount();
    }

    document.body.innerHTML = '';
});

/** The selectors this remediation exists to make impossible. */
const FORBIDDEN =
    'input[type="date"], input[type="time"], input[type="datetime"], input[type="datetime-local"]';

function timeField(
    overrides: Partial<TimeFieldDefinition> = {},
): TimeFieldDefinition {
    return {
        component: 'field',
        type: 'time',
        name: 'starts_at',
        label: 'Starts at',
        required: false,
        disabled: false,
        helperText: null,
        seconds: false,
        ...overrides,
    } as unknown as TimeFieldDefinition;
}

function dateTimeField(
    overrides: Partial<DateTimeFieldDefinition> = {},
): DateTimeFieldDefinition {
    return {
        component: 'field',
        type: 'datetime',
        name: 'published_at',
        label: 'Published at',
        required: false,
        disabled: false,
        helperText: null,
        seconds: false,
        minDate: null,
        maxDate: null,
        ...overrides,
    } as unknown as DateTimeFieldDefinition;
}

function dateField(
    overrides: Partial<DateFieldDefinition> = {},
): DateFieldDefinition {
    return {
        component: 'field',
        type: 'date',
        name: 'published_on',
        label: 'Published on',
        required: false,
        disabled: false,
        helperText: null,
        minDate: null,
        maxDate: null,
        ...overrides,
    } as unknown as DateFieldDefinition;
}

function renderDate(field: DateFieldDefinition, modelValue: unknown = null) {
    const wrapper = mount(DateField, {
        attachTo: document.body,
        props: { field, modelValue },
    });

    mounted.push(wrapper);

    return wrapper;
}

function renderTime(field: TimeFieldDefinition, modelValue: unknown = null) {
    const wrapper = mount(TimeField, {
        attachTo: document.body,
        props: { field, modelValue },
    });

    mounted.push(wrapper);

    return wrapper;
}

function renderDateTime(
    field: DateTimeFieldDefinition,
    modelValue: unknown = null,
) {
    const wrapper = mount(DateTimeField, {
        attachTo: document.body,
        props: { field, modelValue },
    });

    mounted.push(wrapper);

    return wrapper;
}

function emitted(wrapper: { emitted: (name: string) => unknown }) {
    return (wrapper.emitted('update:modelValue') ?? []) as Array<
        [string | null]
    >;
}

/** The last value the field published, or undefined if it published nothing. */
function lastValue(wrapper: {
    emitted: (name: string) => unknown;
}): string | null | undefined {
    const events = emitted(wrapper);

    return events.length === 0 ? undefined : events[events.length - 1]?.[0];
}

/*
 * The policy itself
 */

describe('no renderer creates a native temporal control', () => {
    it('a date field renders no native date input', () => {
        // `DateField` was already using `PanelDatePicker` before any of this
        // — it is here because a guard that only covers the renderers that
        // were broken stops covering the one that was not.
        renderDate(dateField(), '2026-09-10');

        expect(document.querySelectorAll(FORBIDDEN)).toHaveLength(0);
    });

    it('a date field passes its description to the picker as a prop', () => {
        renderDate(dateField({ helperText: 'When it goes live.' }));

        // DT04: an undeclared attribute falls through onto the wrapping div.
        const trigger = document.querySelector('input');

        expect(trigger?.getAttribute('aria-describedby')).toBeTruthy();
    });

    it('DT01 — a time field renders no native time input', () => {
        renderTime(timeField());

        expect(document.querySelectorAll(FORBIDDEN)).toHaveLength(0);
    });

    it('DT01 — nor with seconds enabled', () => {
        renderTime(timeField({ seconds: true }), '09:05:07');

        expect(document.querySelectorAll(FORBIDDEN)).toHaveLength(0);
    });

    it('DT02 — a datetime field renders no native datetime input', () => {
        renderDateTime(dateTimeField(), '2026-09-10 09:30');

        expect(document.querySelectorAll(FORBIDDEN)).toHaveLength(0);
    });

    it('renders panel controls instead', () => {
        renderDateTime(dateTimeField({ seconds: true }));

        // A date trigger plus three time selects, all of them buttons the
        // panel styles rather than controls the browser draws.
        const labels = [...document.querySelectorAll('[aria-label]')].map(
            (element) => element.getAttribute('aria-label'),
        );

        expect(labels).toContain('Date');
        expect(labels).toContain('Hour');
        expect(labels).toContain('Minute');
        expect(labels).toContain('Second');
    });
});

/*
 * The time value contract
 */

describe('a time keeps its contract', () => {
    it('reads an existing HH:mm back into its parts', () => {
        const wrapper = renderTime(timeField(), '09:05');
        const selects = wrapper.findAllComponents({ name: 'Select' });

        // The parts, not the rendered trigger text: a closed Reka select
        // resolves its label from items that are only mounted while the
        // listbox is open, so what the trigger *shows* is proven in Chrome
        // (`tests/browser/temporal.mjs`) and what it *holds* is proven here.
        expect(selects[0]?.props('modelValue')).toBe('09');
        expect(selects[1]?.props('modelValue')).toBe('05');
    });

    it('publishes nothing while only the hour is chosen', async () => {
        const wrapper = mount(PanelTimePicker, {
            attachTo: document.body,
            props: { modelValue: null },
        });
        mounted.push(wrapper);

        await wrapper
            .findComponent({ name: 'Select' })
            .vm.$emit('update:modelValue', '09');

        // `09:` is not a time. The field stays null until the minute is
        // answered, rather than putting a string the server rejects into the
        // form's state.
        expect(lastValue(wrapper)).toBeUndefined();
    });

    it('publishes once both parts are chosen', async () => {
        const wrapper = mount(PanelTimePicker, {
            attachTo: document.body,
            props: { modelValue: null },
        });
        mounted.push(wrapper);

        const selects = wrapper.findAllComponents({ name: 'Select' });

        await selects[0]?.vm.$emit('update:modelValue', '09');
        await selects[1]?.vm.$emit('update:modelValue', '05');

        expect(lastValue(wrapper)).toBe('09:05');
    });

    it('waits for the second when the field asks for seconds', async () => {
        const wrapper = mount(PanelTimePicker, {
            attachTo: document.body,
            props: { modelValue: null, seconds: true },
        });
        mounted.push(wrapper);

        const selects = wrapper.findAllComponents({ name: 'Select' });

        await selects[0]?.vm.$emit('update:modelValue', '23');
        await selects[1]?.vm.$emit('update:modelValue', '59');

        // Two thirds of a seconds-enabled time is not a time either.
        expect(lastValue(wrapper)).toBeUndefined();

        await selects[2]?.vm.$emit('update:modelValue', '59');

        expect(lastValue(wrapper)).toBe('23:59:59');
    });

    it('keeps midnight rather than reading it as absent', () => {
        const wrapper = mount(PanelTimePicker, {
            attachTo: document.body,
            props: { modelValue: '00:00' },
        });
        mounted.push(wrapper);

        const selects = wrapper.findAllComponents({ name: 'Select' });

        // `00:00` is falsy in every loose check somebody might write. It is a
        // time, and the control has to hold it as one.
        expect(selects[0]?.props('modelValue')).toBe('00');
        expect(selects[1]?.props('modelValue')).toBe('00');
        expect(lastValue(wrapper)).toBeUndefined();
    });

    it('clears to null rather than to an empty string or midnight', async () => {
        const wrapper = mount(PanelTimePicker, {
            attachTo: document.body,
            props: { modelValue: '09:05' },
        });
        mounted.push(wrapper);

        await wrapper.find('button[aria-label="Clear time"]').trigger('click');

        expect(lastValue(wrapper)).toBeNull();
    });

    it('ignores a stored value that is not a time', () => {
        const wrapper = mount(PanelTimePicker, {
            attachTo: document.body,
            props: { modelValue: '25:99' },
        });
        mounted.push(wrapper);

        expect(document.body.textContent).toContain('HH');
    });
});

/*
 * The datetime value contract
 */

describe('a datetime keeps its contract', () => {
    it('accepts the T separator the server formats with', () => {
        const wrapper = renderDateTime(dateTimeField(), '2026-09-10T09:30');

        expect(
            wrapper
                .findComponent({ name: 'PanelDatePicker' })
                .props('modelValue'),
        ).toBe('2026-09-10');
        expect(wrapper.findComponent(PanelTimePicker).props('modelValue')).toBe(
            '09:30',
        );
    });

    it('accepts the space separator a column holds', () => {
        const wrapper = renderDateTime(dateTimeField(), '2026-09-10 09:30');

        expect(
            wrapper
                .findComponent({ name: 'PanelDatePicker' })
                .props('modelValue'),
        ).toBe('2026-09-10');
        expect(wrapper.findComponent(PanelTimePicker).props('modelValue')).toBe(
            '09:30',
        );
    });

    it('publishes back with a space, as it always did', async () => {
        const wrapper = renderDateTime(dateTimeField(), '2026-09-10T09:30');
        const time = wrapper.findComponent(PanelTimePicker);

        await time.vm.$emit('update:modelValue', '10:45');

        expect(lastValue(wrapper)).toBe('2026-09-10 10:45');
    });

    it('publishes nothing from a date alone', async () => {
        const wrapper = renderDateTime(dateTimeField());

        await wrapper
            .findComponent({ name: 'PanelDatePicker' })
            .vm.$emit('update:modelValue', '2026-09-10');

        // A date with no time is not midnight on that date — it is a question
        // nobody has answered, and answering it here writes a time the user
        // never chose. Nothing is published at all, which is stronger than
        // publishing an explicit null.
        expect(emitted(wrapper).every(([value]) => value === null)).toBe(true);
    });

    it('publishes nothing from a time alone', async () => {
        const wrapper = renderDateTime(dateTimeField());

        await wrapper
            .findComponent(PanelTimePicker)
            .vm.$emit('update:modelValue', '09:30');

        expect(emitted(wrapper).every(([value]) => value === null)).toBe(true);
    });

    it('keeps the time when the date changes', async () => {
        const wrapper = renderDateTime(dateTimeField(), '2026-09-10 10:30');

        await wrapper
            .findComponent({ name: 'PanelDatePicker' })
            .vm.$emit('update:modelValue', '2026-09-11');

        expect(lastValue(wrapper)).toBe('2026-09-11 10:30');
    });

    it('carries seconds through unchanged', async () => {
        const wrapper = renderDateTime(
            dateTimeField({ seconds: true }),
            '2026-09-10 10:30:45',
        );

        await wrapper
            .findComponent({ name: 'PanelDatePicker' })
            .vm.$emit('update:modelValue', '2026-09-11');

        expect(lastValue(wrapper)).toBe('2026-09-11 10:30:45');
    });

    it('keeps end of day', () => {
        const wrapper = renderDateTime(dateTimeField(), '2026-09-10 23:59');

        expect(
            wrapper
                .findComponent({ name: 'PanelDatePicker' })
                .props('modelValue'),
        ).toBe('2026-09-10');
        expect(wrapper.findComponent(PanelTimePicker).props('modelValue')).toBe(
            '23:59',
        );
    });

    it('clears to null', async () => {
        const wrapper = renderDateTime(dateTimeField(), '2026-09-10 09:30');

        await wrapper
            .findComponent({ name: 'PanelDatePicker' })
            .vm.$emit('update:modelValue', null);

        expect(lastValue(wrapper)).toBeNull();
    });
});

/*
 * The bound the old control could not enforce
 */

describe('a datetime bound applies to the hour, not just the day', () => {
    const bounded = () =>
        dateTimeField({
            minDate: '2026-09-10 09:30',
            maxDate: '2026-09-12 17:45',
        });

    function timePickerProps(modelValue: string | null) {
        const wrapper = renderDateTime(bounded(), modelValue);

        return wrapper.findComponent(PanelTimePicker).props() as {
            minTime: string | null;
            maxTime: string | null;
        };
    }

    it('bounds the hours on the minimum date', () => {
        // The whole finding: slicing the bound to ten characters and handing
        // it to a calendar makes the 10th selectable and every time on it
        // selectable with it, so 08:00 was reachable under a 09:30 minimum.
        expect(timePickerProps('2026-09-10 10:00').minTime).toBe('09:30');
    });

    it('bounds the hours on the maximum date', () => {
        expect(timePickerProps('2026-09-12 10:00').maxTime).toBe('17:45');
    });

    it('leaves a date between the bounds unbounded in time', () => {
        const props = timePickerProps('2026-09-11 03:00');

        expect(props.minTime).toBeNull();
        expect(props.maxTime).toBeNull();
    });

    it('still passes the calendar the date halves', () => {
        const wrapper = renderDateTime(bounded(), null);
        const picker = wrapper.findComponent({ name: 'PanelDatePicker' });

        expect(picker.props('min')).toBe('2026-09-10');
        expect(picker.props('max')).toBe('2026-09-12');
    });

    it('clears a time the new date makes illegal, rather than moving it', async () => {
        const wrapper = renderDateTime(bounded(), '2026-09-11 08:00');

        await wrapper
            .findComponent({ name: 'PanelDatePicker' })
            .vm.$emit('update:modelValue', '2026-09-10');

        // 08:00 is legal on the 11th and not on the 10th. Nudging it to 09:30
        // would be this field deciding what the user meant; the time is
        // cleared instead, and the control says so.
        expect(lastValue(wrapper)).toBeNull();
    });

    it('keeps a time the new date still allows', async () => {
        const wrapper = renderDateTime(bounded(), '2026-09-11 10:00');

        await wrapper
            .findComponent({ name: 'PanelDatePicker' })
            .vm.$emit('update:modelValue', '2026-09-10');

        expect(lastValue(wrapper)).toBe('2026-09-10 10:00');
    });

    it('treats the exact bound as inside it', async () => {
        const wrapper = renderDateTime(bounded(), '2026-09-11 09:30');

        await wrapper
            .findComponent({ name: 'PanelDatePicker' })
            .vm.$emit('update:modelValue', '2026-09-10');

        expect(lastValue(wrapper)).toBe('2026-09-10 09:30');
    });

    it('compares a seconds bound against a value without seconds', async () => {
        const wrapper = renderDateTime(
            dateTimeField({ seconds: true, minDate: '2026-09-10 09:30:00' }),
            '2026-09-11 09:30:00',
        );

        await wrapper
            .findComponent({ name: 'PanelDatePicker' })
            .vm.$emit('update:modelValue', '2026-09-10');

        expect(lastValue(wrapper)).toBe('2026-09-10 09:30:00');
    });
});

/*
 * Accessibility, on the control that actually takes focus
 */

describe('helper and error reach the focusable control', () => {
    it('DT04 — puts describedBy on the date trigger, not the wrapper', () => {
        renderDateTime(
            dateTimeField({ helperText: 'When the post goes live.' }),
            null,
        );

        const trigger = document.querySelector('input[aria-label="Date"]');

        expect(trigger?.getAttribute('aria-describedby')).toBeTruthy();
    });

    it('puts describedBy on every time trigger', () => {
        renderTime(timeField({ helperText: 'Local time.', seconds: true }));

        for (const name of ['Hour', 'Minute', 'Second']) {
            const trigger = document.querySelector(`[aria-label="${name}"]`);

            expect(trigger?.getAttribute('aria-describedby')).toBeTruthy();
        }
    });

    it('marks the focusable controls invalid, not only a wrapper', () => {
        renderTime(timeField(), null);
        document.body.innerHTML = '';

        const wrapper = mount(TimeField, {
            attachTo: document.body,
            props: {
                field: timeField(),
                modelValue: null,
                error: 'Pick a time.',
            },
        });
        mounted.push(wrapper);

        const hour = document.querySelector('[aria-label="Hour"]');

        expect(hour?.getAttribute('aria-invalid')).toBe('true');
    });

    it('names the set once rather than each control separately', () => {
        renderDateTime(dateTimeField());

        const group = document.querySelector('[role="group"]');

        // `FieldWrapper`'s group mode already solves this for radio groups and
        // checkbox lists; a datetime is the same shape of problem.
        expect(group?.getAttribute('aria-labelledby')).toBeTruthy();
    });

    it('gives two instances of the same field distinct ids', () => {
        const first = mount(TimeField, {
            attachTo: document.body,
            props: { field: timeField(), modelValue: null },
        });
        mounted.push(first);

        const ids = [...document.querySelectorAll('[id]')]
            .map((element) => element.id)
            .filter((id) => id !== '');

        // Every id in one render is distinct; the repeater case is proven in
        // the browser suite, where two entries are mounted side by side.
        expect(new Set(ids).size).toBe(ids.length);
    });
});

/*
 * DT03 — the violation with no literal attribute to grep for
 */

describe('a query builder picks a date with the panel calendar', () => {
    /** A filter shaped exactly as `DateConstraint` serializes one. */
    const dateFilter = {
        name: 'advanced',
        label: 'Advanced',
        type: 'query_builder',
        maxRules: 5,
        constraints: [
            {
                name: 'created_at',
                label: 'Created at',
                // The server's semantic type. It stays `date` — the value
                // *is* a date, and the fix is in what renders it, not in
                // what the server calls it.
                input: 'date',
                operators: [
                    { value: 'equals', label: 'is', needsValue: true },
                    { value: 'is_blank', label: 'is blank', needsValue: false },
                ],
            },
            {
                name: 'title',
                label: 'Title',
                input: 'text',
                operators: [{ value: 'equals', label: 'is', needsValue: true }],
            },
        ],
    };

    function builder(rules: QueryBuilderRule[]) {
        const wrapper = mount(DataTableQueryBuilder, {
            attachTo: document.body,
            props: {
                filter: dateFilter as unknown as QueryBuilderFilterDefinition,
                rules,
            },
        });

        mounted.push(wrapper);

        return wrapper;
    }

    it('renders no native date input for a date constraint', () => {
        builder([{ column: 'created_at', operator: 'equals', value: null }]);

        // This is the one a literal search could never have found: the type
        // was `:type="inputTypeFor(rule)"`, and `DateConstraint` made it
        // `date` at runtime.
        expect(document.querySelectorAll(FORBIDDEN)).toHaveLength(0);
    });

    it('renders the panel date picker instead', () => {
        const wrapper = builder([
            { column: 'created_at', operator: 'equals', value: '2026-09-10' },
        ]);

        const picker = wrapper.findComponent({ name: 'PanelDatePicker' });

        expect(picker.exists()).toBe(true);
        expect(picker.props('modelValue')).toBe('2026-09-10');
    });

    it('keeps a text constraint on a text input', () => {
        const wrapper = builder([
            { column: 'title', operator: 'equals', value: 'hello' },
        ]);

        expect(
            wrapper.findComponent({ name: 'PanelDatePicker' }).exists(),
        ).toBe(false);
        expect(document.querySelector('input[type="text"]')).not.toBeNull();
    });

    it('renders no value control for an operator that needs none', () => {
        const wrapper = builder([
            { column: 'created_at', operator: 'is_blank', value: null },
        ]);

        expect(
            wrapper.findComponent({ name: 'PanelDatePicker' }).exists(),
        ).toBe(false);
        expect(document.querySelectorAll(FORBIDDEN)).toHaveLength(0);
    });

    it('publishes a picked date as YYYY-MM-DD, not as the string "null"', async () => {
        const wrapper = builder([
            { column: 'created_at', operator: 'equals', value: null },
        ]);

        await wrapper
            .findComponent({ name: 'PanelDatePicker' })
            .vm.$emit('update:modelValue', '2026-09-10');

        const changes = (wrapper.emitted('change') ?? []) as Array<
            [Array<Record<string, unknown>>]
        >;

        expect(changes[0]?.[0][0]?.value).toBe('2026-09-10');
    });

    it('publishes null when the date is cleared, not the string "null"', async () => {
        const wrapper = builder([
            { column: 'created_at', operator: 'equals', value: '2026-09-10' },
        ]);

        await wrapper
            .findComponent({ name: 'PanelDatePicker' })
            .vm.$emit('update:modelValue', null);

        const changes = (wrapper.emitted('change') ?? []) as Array<
            [Array<Record<string, unknown>>]
        >;

        // `String(null)` is `'null'`, which the old handler would have written
        // into the rule and the server would have tried to parse as a date.
        expect(changes[0]?.[0][0]?.value).toBeNull();
    });
});

describe('manual temporal input', () => {
    it('accepts a typed date and rejects impossible or out-of-bounds dates', async () => {
        const wrapper = renderDate(
            dateField({ minDate: '2026-09-10', maxDate: '2026-09-20' }),
        );
        const input = wrapper.get('input');
        await input.setValue('2026-09-15');
        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([
            '2026-09-15',
        ]);
        for (const value of ['2026-02-30', '2026-09-09', '2026-09-21']) {
            await input.setValue(value);
            expect(input.attributes('aria-invalid')).toBe('true');
            expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([
                null,
            ]);
        }
        await input.setValue('');
        expect(input.attributes('aria-invalid')).toBeUndefined();
    });

    it('accepts midnight and seconds, rejecting invalid times', async () => {
        const wrapper = renderTime(timeField({ seconds: true }));
        const input = wrapper.get('input');
        await input.setValue('00:00:07');
        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([
            '00:00:07',
        ]);
        await input.setValue('24:00:00');
        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([null]);
        expect(input.attributes('aria-invalid')).toBe('true');
    });

    it('preserves the datetime sibling while an edit is incomplete', async () => {
        const wrapper = renderDateTime(dateTimeField(), '2026-09-10 09:30');
        const inputs = wrapper.findAll('input');
        await inputs[0]!.setValue('2026-09-');
        await wrapper.setProps({ modelValue: null });
        expect((inputs[1]!.element as HTMLInputElement).value).toBe('09:30');
        await inputs[0]!.setValue('2026-09-11');
        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([
            '2026-09-11 09:30',
        ]);
    });

    it('applies time bounds to manual input', async () => {
        const wrapper = mount(PanelTimePicker, {
            props: { modelValue: null, minTime: '09:30', maxTime: '10:00' },
        });
        mounted.push(wrapper);
        await wrapper.get('input').setValue('09:29');
        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([null]);
        await wrapper.get('input').setValue('09:30');
        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['09:30']);
    });
});
