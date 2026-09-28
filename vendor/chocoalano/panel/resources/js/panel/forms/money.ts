/**
 * Reading and writing amounts of money the way a locale writes them.
 *
 * Kept out of the component so the arithmetic — which character a caret sits
 * after, what a keystroke turned `1.234` into — can be tested without a DOM.
 *
 * Three shapes of an amount meet here:
 *
 *   text     what is on screen:        `-12.500,5`  (id)   `-12,500.5`  (en)
 *   Amount   what was typed, in parts: { negative, integer, fraction }
 *   value    what the form submits:    `-12500.5`  — a string, never a float
 *
 * The value is a string because it is money. `0.1 + 0.2` is not `0.3`, and an
 * amount that drifted by a fraction of a cent between the input and the
 * record would be found by an accountant rather than by a test.
 */

export interface MoneyFormat {
    /** What the amount wears: `Rp`, `$`, `€`. */
    symbol: string;
    /** Whether this locale writes the symbol before the number. */
    symbolFirst: boolean;
    /** The thousands separator. */
    group: string;
    /** The decimal separator. */
    decimal: string;
    /** Digits allowed after the decimal separator. */
    decimals: number;
    /** Whether a leading minus is kept or ignored. */
    negative: boolean;
    /** A locale `Intl` is known to accept. */
    locale: string;
}

export interface Amount {
    negative: boolean;
    /** Digits before the separator, without leading zeros; empty when none. */
    integer: string;
    /** Digits after it; null when no separator was typed at all. */
    fraction: string | null;
}

/**
 * An `Intl.NumberFormat`, falling back to English for a locale tag `Intl`
 * refuses — a field's `locale()` is a developer's string, and a typo in it
 * must not take the form down.
 */
function numberFormat(
    locale: string,
    options: Intl.NumberFormatOptions,
): Intl.NumberFormat {
    try {
        return new Intl.NumberFormat(locale, options);
    } catch {
        return new Intl.NumberFormat('en', options);
    }
}

/*
 * Latin digits whatever the locale's own numbering system: the parser reads
 * 0–9, and an Arabic-Indic `١٢٣` it could not read back would erase itself on
 * the next keystroke.
 */
const LATIN: Intl.NumberFormatOptions = { numberingSystem: 'latn' };

export function moneyFormat(options: {
    locale: string;
    currency: string;
    decimals: number;
    negative: boolean;
}): MoneyFormat {
    const plain = numberFormat(options.locale, LATIN);
    const parts = plain.formatToParts(1234567.5);

    let symbol = options.currency;
    let symbolFirst = true;

    try {
        const written = numberFormat(options.locale, {
            ...LATIN,
            style: 'currency',
            currency: options.currency,
            currencyDisplay: 'narrowSymbol',
        }).formatToParts(1);

        const currency = written.findIndex((part) => part.type === 'currency');
        const integer = written.findIndex((part) => part.type === 'integer');

        if (currency !== -1) {
            symbol = written[currency].value;
            symbolFirst = currency < integer;
        }
    } catch {
        // A code `Intl` does not know: the code itself, in front, is still a
        // truthful label.
    }

    return {
        symbol,
        symbolFirst,
        group: parts.find((part) => part.type === 'group')?.value ?? ',',
        decimal: parts.find((part) => part.type === 'decimal')?.value ?? '.',
        decimals: options.decimals,
        negative: options.negative,
        locale: plain.resolvedOptions().locale,
    };
}

/** Drops leading zeros, keeping one when the number *is* zero. */
function trimZeros(digits: string): string {
    return digits.replace(/^0+(?=\d)/, '');
}

function meaningful(char: string, format: MoneyFormat): boolean {
    return (
        (char >= '0' && char <= '9') ||
        (format.decimals > 0 && char === format.decimal) ||
        (format.negative && (char === '-' || char === '−'))
    );
}

/**
 * What a person typed, read in their locale.
 *
 * Anything that is not a digit, the decimal separator, or a leading minus is
 * ignored — group separators, the symbol if it was pasted in, a stray letter.
 * The first decimal separator is the one; digits past `decimals` are dropped
 * rather than rounded, because a keystroke that changed a digit the person
 * already typed would be the control arguing with them.
 */
export function readTyped(text: string, format: MoneyFormat): Amount {
    const at = format.decimals > 0 ? text.indexOf(format.decimal) : -1;
    const before = at === -1 ? text : text.slice(0, at);
    const after = at === -1 ? null : text.slice(at + format.decimal.length);

    return {
        negative: format.negative && /^\s*[-−]/.test(text),
        integer: trimZeros(before.replace(/\D/g, '')),
        fraction:
            after === null
                ? null
                : after.replace(/\D/g, '').slice(0, format.decimals),
    };
}

/**
 * A value the form holds — from the server or from this control — or null
 * when there is none, or it is not an amount at all.
 */
export function readValue(value: unknown): Amount | null {
    const text =
        typeof value === 'number' && Number.isFinite(value)
            ? String(value)
            : typeof value === 'string'
              ? value.trim()
              : null;

    const match =
        text === null ? null : /^([+-]?)(\d*)(?:\.(\d*))?$/.exec(text);

    if (match === null || match[2] + (match[3] ?? '') === '') {
        return null;
    }

    return {
        negative: match[1] === '-',
        integer: trimZeros(match[2]),
        fraction: match[3] ?? null,
    };
}

function groupDigits(digits: string, format: MoneyFormat): string {
    try {
        // BigInt, so an amount past 2^53 is grouped rather than rounded.
        return numberFormat(format.locale, {
            ...LATIN,
            maximumFractionDigits: 0,
        }).format(BigInt(digits));
    } catch {
        return digits;
    }
}

/**
 * The amount as it should read on screen.
 *
 * `pad` writes every decimal place — `12.500,50` rather than `12.500,5` —
 * which is right once somebody has finished typing and wrong while they are
 * still doing it.
 */
export function writeAmount(
    amount: Amount,
    format: MoneyFormat,
    pad: boolean,
): string {
    if (amount.integer === '' && amount.fraction === null) {
        return amount.negative ? '-' : '';
    }

    const integer = groupDigits(
        amount.integer === '' ? '0' : amount.integer,
        format,
    );

    let fraction = amount.fraction?.slice(0, format.decimals) ?? null;

    if (pad && format.decimals > 0) {
        fraction = (fraction ?? '').padEnd(format.decimals, '0');
    }

    return (
        (amount.negative ? '-' : '') +
        integer +
        (fraction !== null && format.decimals > 0
            ? format.decimal + fraction
            : '')
    );
}

/** What the form submits for an amount: `-12500.5`, or null for nothing. */
export function amountValue(amount: Amount): string | null {
    const fraction = amount.fraction ?? '';

    if (amount.integer === '' && fraction === '') {
        return null;
    }

    const integer = amount.integer === '' ? '0' : amount.integer;
    const zero = /^0*$/.test(integer + fraction);

    return (
        (amount.negative && !zero ? '-' : '') +
        integer +
        (fraction !== '' ? `.${fraction}` : '')
    );
}

/**
 * Whether two values are the same amount: `12500.5`, `12500.50` and the
 * number `12500.5` all are.
 */
export function sameAmount(a: unknown, b: unknown): boolean {
    const normalize = (value: unknown): string | null => {
        const amount = readValue(value);

        if (amount === null) {
            return null;
        }

        const fraction = (amount.fraction ?? '').replace(/0+$/, '');

        return amountValue({ ...amount, fraction });
    };

    return normalize(a) === normalize(b);
}

export interface Retyped {
    text: string;
    caret: number;
    value: string | null;
}

/**
 * Re-reads the input after a keystroke: the text regrouped, the caret put
 * back where it belongs, and the value to submit.
 *
 * The caret is placed by counting the characters that mean something —
 * digits, the separator, the sign — before it, and finding the same count in
 * the new text. Group separators come and go as digits are added; counting
 * them would walk the caret sideways every time a thousand was crossed.
 */
export function retype(
    text: string,
    caret: number,
    format: MoneyFormat,
): Retyped {
    let count = 0;

    for (const char of text.slice(0, caret)) {
        if (meaningful(char, format)) {
            count += 1;
        }
    }

    const amount = readTyped(text, format);
    const written = writeAmount(amount, format, false);

    // `,5` is written `0,5`: a zero nobody typed, and one more character
    // before a caret that sits after the separator.
    if (
        amount.integer === '' &&
        amount.fraction !== null &&
        text.indexOf(format.decimal) < caret
    ) {
        count += 1;
    }

    let position = 0;
    let seen = 0;

    while (position < written.length && seen < count) {
        if (meaningful(written[position], format)) {
            seen += 1;
        }

        position += 1;
    }

    return { text: written, caret: position, value: amountValue(amount) };
}

/**
 * Where the caret should be before Backspace or Delete acts.
 *
 * Deleting a group separator on its own would do nothing — the next keystroke
 * regroups the digits and puts it straight back — so the caret steps over it
 * and the key removes the digit beyond instead.
 */
export function stepOverSeparator(
    text: string,
    caret: number,
    direction: 'backward' | 'forward',
    format: MoneyFormat,
): number {
    if (direction === 'backward') {
        return caret > 0 && !meaningful(text[caret - 1], format)
            ? caret - 1
            : caret;
    }

    return caret < text.length && !meaningful(text[caret], format)
        ? caret + 1
        : caret;
}
