import type {
    ConditionDefinition,
    FieldConditions,
    FormValue,
    FormValues,
} from '@/panel/types/form';

/**
 * Re-evaluates a field's visibility as the user types.
 *
 * The server sends a *description* of each comparison, never code — see
 * `PandaPanel\Forms\Enums\ConditionOperator` for why. This module is the
 * compiled-in half: it knows how to perform the ten comparisons the schema
 * can name, and nothing else.
 *
 * It deliberately mirrors the PHP implementation operator for operator. The
 * two must agree, because the same conditions decide what is rendered here
 * and what is validated there; a field the browser hid but the server still
 * required would be a form that cannot be submitted and does not say why.
 */

/**
 * Compared as strings, so `'1'` from a form and `1` from a model are the same
 * answer. A form's values arrive as text whatever the column holds.
 */
function scalar(value: unknown): string {
    if (value === null || value === undefined) {
        return '';
    }

    if (typeof value === 'boolean') {
        return value ? '1' : '0';
    }

    if (typeof value === 'string' || typeof value === 'number') {
        return String(value);
    }

    return '';
}

function isBlank(value: unknown): boolean {
    return (
        value === null ||
        value === undefined ||
        value === '' ||
        (Array.isArray(value) && value.length === 0)
    );
}

function isNumeric(value: unknown): boolean {
    if (typeof value === 'number') {
        return Number.isFinite(value);
    }

    return (
        typeof value === 'string' &&
        value.trim() !== '' &&
        Number.isFinite(Number(value))
    );
}

/**
 * PHP's truthiness, not JavaScript's: `'0'` is false in PHP and true here,
 * and a checkbox that sends `'0'` must mean the same thing on both sides.
 */
function isTruthy(value: unknown): boolean {
    if (typeof value === 'string') {
        return value !== '' && value !== '0';
    }

    if (Array.isArray(value)) {
        return value.length > 0;
    }

    if (typeof value === 'number') {
        return value !== 0;
    }

    return Boolean(value);
}

function matchesCondition(
    condition: ConditionDefinition,
    values: FormValues,
): boolean {
    const state = values[condition.field] ?? null;
    const expected = condition.value;

    switch (condition.operator) {
        case 'equals':
            return scalar(state) === scalar(expected);
        case 'not_equals':
            return scalar(state) !== scalar(expected);
        case 'in':
            return (
                Array.isArray(expected) &&
                expected.map(scalar).includes(scalar(state))
            );
        case 'not_in':
            return (
                !Array.isArray(expected) ||
                !expected.map(scalar).includes(scalar(state))
            );
        case 'filled':
            return !isBlank(state);
        case 'blank':
            return isBlank(state);
        case 'greater_than':
            return (
                isNumeric(state) &&
                isNumeric(expected) &&
                Number(state) > Number(expected)
            );
        case 'less_than':
            return (
                isNumeric(state) &&
                isNumeric(expected) &&
                Number(state) < Number(expected)
            );
        case 'truthy':
            return isTruthy(state);
        case 'falsy':
            return !isTruthy(state);
    }
}

/**
 * Every `visibleWhen` must hold and no `hiddenWhen` may, which is exactly
 * what `Field::matchesConditions()` decides on the server.
 */
export function matchesConditions(
    conditions: FieldConditions | undefined,
    values: FormValues,
): boolean {
    if (!conditions) {
        return true;
    }

    for (const condition of conditions.visibleWhen ?? []) {
        if (!matchesCondition(condition, values)) {
            return false;
        }
    }

    for (const condition of conditions.hiddenWhen ?? []) {
        if (matchesCondition(condition, values)) {
            return false;
        }
    }

    return true;
}

/**
 * The fields a condition reads, so a change to one of them can be recognised
 * as worth re-evaluating.
 */
export function conditionDependencies(
    conditions: FieldConditions | undefined,
): string[] {
    if (!conditions) {
        return [];
    }

    return [
        ...(conditions.visibleWhen ?? []),
        ...(conditions.hiddenWhen ?? []),
    ].map((condition) => condition.field);
}

/** Re-exported for the value normalizer, which needs the same emptiness. */
export function isBlankValue(value: FormValue): boolean {
    return isBlank(value);
}
