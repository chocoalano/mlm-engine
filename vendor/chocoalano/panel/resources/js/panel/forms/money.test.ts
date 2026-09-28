/**
 * @vitest-environment happy-dom
 */
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import {
    amountValue,
    moneyFormat,
    readTyped,
    retype,
    sameAmount,
    stepOverSeparator,
    writeAmount,
} from '@/panel/forms/money';
import type { MoneyFieldDefinition } from '@/panel/types/form';

const page = vi.hoisted(() => ({ locale: 'id' }));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: { translations: {}, locale: page.locale },
        url: '/',
        component: '',
        version: null,
    }),
}));

const { default: MoneyField } =
    await import('@/panel/forms/fields/MoneyField.vue');

const rupiah = moneyFormat({
    locale: 'id',
    currency: 'IDR',
    decimals: 0,
    negative: false,
});

const rupiahWithSen = moneyFormat({
    locale: 'id',
    currency: 'IDR',
    decimals: 2,
    negative: true,
});

const dollars = moneyFormat({
    locale: 'en',
    currency: 'USD',
    decimals: 2,
    negative: false,
});

/*
 * How the locale writes it
 */

describe('the format a locale gives an amount', () => {
    it('separates and labels rupiah the Indonesian way', () => {
        expect(rupiahWithSen).toMatchObject({
            symbol: 'Rp',
            symbolFirst: true,
            group: '.',
            decimal: ',',
        });
    });

    it('separates and labels dollars the English way', () => {
        expect(dollars).toMatchObject({
            symbol: '$',
            symbolFirst: true,
            group: ',',
            decimal: '.',
        });
    });

    it('puts the symbol after the number where the locale does', () => {
        const euros = moneyFormat({
            locale: 'de',
            currency: 'EUR',
            decimals: 2,
            negative: false,
        });

        expect(euros.symbol).toBe('€');
        expect(euros.symbolFirst).toBe(false);
        expect(euros.decimal).toBe(',');
    });

    it('survives a locale and a currency Intl refuses', () => {
        const odd = moneyFormat({
            locale: 'not a locale',
            currency: 'X',
            decimals: 2,
            negative: false,
        });

        // English separators, and the code itself as the label.
        expect(odd).toMatchObject({ symbol: 'X', group: ',', decimal: '.' });
    });
});

/*
 * Typing
 */

describe('an amount as it is typed', () => {
    it('groups the thousands and submits the plain amount', () => {
        expect(retype('1500000', 7, rupiah)).toEqual({
            text: '1.500.000',
            caret: 9,
            value: '1500000',
        });
    });

    it('keeps the caret beside the digit it was beside', () => {
        // `123.456` with a 9 typed after the 1.
        expect(retype('1923.456', 2, rupiah)).toMatchObject({
            text: '1.923.456',
            caret: 3,
        });
    });

    it('reads the decimal separator of the locale, not of the code', () => {
        expect(retype('12.500,5', 8, rupiahWithSen)).toMatchObject({
            text: '12.500,5',
            value: '12500.5',
        });
        expect(retype('12,500.5', 8, dollars).value).toBe('12500.5');
    });

    it('keeps a trailing separator on screen while submitting the whole amount', () => {
        expect(retype('12,', 3, rupiahWithSen)).toEqual({
            text: '12,',
            caret: 3,
            value: '12',
        });
    });

    it('drops a digit past the currency decimals rather than rounding', () => {
        expect(retype('1,239', 5, rupiahWithSen)).toMatchObject({
            text: '1,23',
            value: '1.23',
        });
    });

    it('ignores the separator of a currency without decimals', () => {
        expect(retype('1.500,75', 8, rupiah).value).toBe('150075');
    });

    it('writes the zero in front of a bare separator', () => {
        expect(retype(',5', 2, rupiahWithSen)).toEqual({
            text: '0,5',
            caret: 3,
            value: '0.5',
        });
    });

    it('ignores letters and leaves the caret where it was', () => {
        expect(retype('12a', 3, rupiah)).toMatchObject({
            text: '12',
            caret: 2,
            value: '12',
        });
    });

    it('drops leading zeros but keeps a zero that is the amount', () => {
        expect(retype('0', 1, rupiah).text).toBe('0');
        expect(retype('05', 2, rupiah)).toMatchObject({ text: '5', caret: 1 });
    });

    it('takes a minus only where the server would accept one', () => {
        expect(retype('-5', 2, rupiah).value).toBe('5');
        expect(retype('-5', 2, rupiahWithSen).value).toBe('-5');
        expect(retype('-', 1, rupiahWithSen)).toMatchObject({
            text: '-',
            value: null,
        });
    });

    it('never passes an amount through a float', () => {
        // Past 2^53, where a Number would already be wrong.
        expect(retype('12345678901234567891', 20, rupiah).value).toBe(
            '12345678901234567891',
        );
    });

    it('treats an emptied input as no amount', () => {
        expect(retype('', 0, rupiah)).toEqual({
            text: '',
            caret: 0,
            value: null,
        });
    });

    it('steps over a group separator before deleting', () => {
        expect(stepOverSeparator('1.234', 2, 'backward', rupiah)).toBe(1);
        expect(stepOverSeparator('1.234', 1, 'forward', rupiah)).toBe(2);
        expect(stepOverSeparator('1.234', 5, 'backward', rupiah)).toBe(5);
    });
});

describe('an amount at rest', () => {
    it('writes every decimal place once padded', () => {
        const amount = readTyped('12.500,5', rupiahWithSen);

        expect(writeAmount(amount, rupiahWithSen, true)).toBe('12.500,50');
        expect(writeAmount(amount, rupiah, true)).toBe('12.500');
    });

    it('recognises the same amount however it is spelled', () => {
        expect(sameAmount('12500.5', '12500.50')).toBe(true);
        expect(sameAmount(12500.5, '12500.50')).toBe(true);
        expect(sameAmount('-0', '0.00')).toBe(true);
        expect(sameAmount(null, '')).toBe(true);
        expect(sameAmount('1', '2')).toBe(false);
    });

    it('never submits a negative zero', () => {
        expect(
            amountValue({ negative: true, integer: '0', fraction: '00' }),
        ).toBe('0.00');
    });
});

/*
 * The control
 */

function field(overrides: Partial<MoneyFieldDefinition> = {}) {
    return {
        component: 'field',
        name: 'price',
        label: 'Price',
        type: 'money',
        value: null,
        placeholder: null,
        helperText: null,
        required: false,
        disabled: false,
        inlineLabel: false,
        columnSpan: 'full',
        conditions: { visibleWhen: [], hiddenWhen: [] },
        live: null,
        validation: { required: false },
        currency: 'IDR',
        decimals: 2,
        locale: null,
        min: 0,
        max: null,
        ...overrides,
    } as MoneyFieldDefinition;
}

const mounted: Array<{ unmount: () => void }> = [];

afterEach(() => {
    while (mounted.length > 0) {
        mounted.pop()?.unmount();
    }

    page.locale = 'id';
});

/**
 * A MoneyField whose parent does what a form does: stores what it emits and
 * hands it straight back as the model value.
 */
function render(
    modelValue: unknown,
    overrides: Partial<MoneyFieldDefinition> = {},
) {
    const wrapper = mount(MoneyField, {
        attachTo: document.body,
        props: {
            field: field(overrides),
            modelValue,
            'onUpdate:modelValue': (value: string | null) =>
                wrapper.setProps({ modelValue: value }),
        },
    });

    mounted.push(wrapper);

    return wrapper;
}

async function type(
    wrapper: ReturnType<typeof render>,
    text: string,
): Promise<HTMLInputElement> {
    const input = wrapper.get('input').element as HTMLInputElement;

    input.value = text;
    input.setSelectionRange(text.length, text.length);
    input.dispatchEvent(new Event('input'));
    await nextTick();

    return input;
}

describe('the money field', () => {
    it('shows the stored amount the way the locale writes it', () => {
        const wrapper = render('12500.5');

        expect(wrapper.get('input').element.value).toBe('12.500,50');
        expect(wrapper.text()).toContain('Rp');
    });

    it('submits the plain amount, as a string', async () => {
        const wrapper = render(null);

        await type(wrapper, '1500000,25');

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([
            '1500000.25',
        ]);
    });

    it('keeps what is being typed when the form hands the amount back', async () => {
        const wrapper = render(null);

        // `12,` stands for 12. The form echoes `12` back; rewriting the text
        // from that would eat the separator somebody is about to type after.
        const input = await type(wrapper, '12,');

        expect(wrapper.props('modelValue')).toBe('12');
        expect(input.value).toBe('12,');
    });

    it('replaces the text when the amount changes from outside', async () => {
        const wrapper = render('10');

        await wrapper.setProps({ modelValue: '2500' });

        expect(wrapper.get('input').element.value).toBe('2.500,00');
    });

    it('writes every decimal place once somebody leaves', async () => {
        const wrapper = render(null);

        const input = await type(wrapper, '12,5');

        await wrapper.get('input').trigger('blur');

        expect(input.value).toBe('12,50');
        // The same amount: nothing new is submitted for the padding.
        expect(wrapper.emitted('update:modelValue')).toHaveLength(1);
    });

    it('does not emit for a keystroke that changes nothing', async () => {
        const wrapper = render('12');

        await type(wrapper, '12,00x');

        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    });

    it('names the currency to a screen reader', () => {
        const wrapper = render(null);

        const input = wrapper.get('input');
        const ids = input.attributes('aria-describedby')?.split(' ') ?? [];

        expect(
            ids.map((id) => document.getElementById(id)?.textContent?.trim()),
        ).toContain('Rp');
        expect(input.attributes('inputmode')).toBe('decimal');
    });

    it('follows the panel locale unless the field names one', () => {
        page.locale = 'en';

        expect(render('1234.5').get('input').element.value).toBe('1,234.50');
        expect(
            render('1234.5', { locale: 'id-ID' }).get('input').element.value,
        ).toBe('1.234,50');
    });
});
