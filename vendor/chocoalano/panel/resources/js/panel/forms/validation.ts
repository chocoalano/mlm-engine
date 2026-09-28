import { matchesConditions } from '@/panel/forms/conditions';
import type {
    FieldDefinition,
    FormValue,
    FormValues,
    ValidationHints,
} from '@/panel/types/form';

/**
 * Checks what a browser can honestly check, from the same declarations the
 * server validates with.
 *
 * This is a courtesy, not a control. Every rule here runs again on the
 * server, and the rules that cannot run here at all — `unique`, `exists`,
 * anything needing the database — are absent rather than guessed at. A form
 * that passes this can still be rejected, and that is correct: the server is
 * the only place the answer is authoritative.
 *
 * The value is the round trip it saves. Typing an address into an email field
 * and being told about the typo without a request is a better form; being
 * told a name is taken without one would be a lie.
 */

/**
 * Deliberately permissive, and deliberately not the server's pattern.
 *
 * A stricter check here than the server's would reject an address the server
 * would have accepted, which is the one failure mode this must not have.
 */
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

function isBlank(value: FormValue): boolean {
    if (value === null || value === undefined || value === '') {
        return true;
    }

    if (Array.isArray(value)) {
        return value.length === 0;
    }

    if (typeof value === 'object') {
        return Object.keys(value).length === 0;
    }

    return false;
}

/**
 * What `min` and `max` measure, following Laravel: a number's value, a
 * string's length, a collection's count.
 */
function measure(value: FormValue, hints: ValidationHints): number | null {
    if (Array.isArray(value)) {
        return value.length;
    }

    if (typeof value === 'number') {
        return value;
    }

    if (typeof value === 'string') {
        return hints.numeric ? Number(value) : value.length;
    }

    return null;
}

function unit(value: FormValue, hints: ValidationHints): string {
    if (Array.isArray(value)) {
        return 'items';
    }

    return typeof value === 'string' && !hints.numeric ? 'characters' : '';
}

/**
 * The messages one field produces, or an empty list when it is fine.
 */
function fieldErrors(field: FieldDefinition, values: FormValues): string[] {
    const hints = field.validation ?? {};
    const value = values[field.name] ?? null;
    const label = field.label;
    const messages: string[] = [];

    if (hints.required && isBlank(value)) {
        return [`The ${label} field is required.`];
    }

    // Every other rule describes a value that is there. A blank optional
    // field is not malformed, it is absent.
    if (isBlank(value)) {
        return [];
    }

    if (hints.email && typeof value === 'string' && !EMAIL.test(value)) {
        messages.push(`The ${label} field must be a valid email address.`);
    }

    if (hints.url && typeof value === 'string') {
        try {
            new URL(value);
        } catch {
            messages.push(`The ${label} field must be a valid URL.`);
        }
    }

    if (hints.numeric && Number.isNaN(Number(value))) {
        messages.push(`The ${label} field must be a number.`);
    }

    const size = measure(value, hints);
    const suffix = unit(value, hints);

    if (size !== null && hints.min !== undefined && size < hints.min) {
        messages.push(
            `The ${label} field must be at least ${hints.min} ${suffix}.`.replace(
                ' .',
                '.',
            ),
        );
    }

    if (size !== null && hints.max !== undefined && size > hints.max) {
        messages.push(
            `The ${label} field must not be greater than ${hints.max} ${suffix}.`.replace(
                ' .',
                '.',
            ),
        );
    }

    if (
        hints.confirmed &&
        values[`${field.name}_confirmation`] !== values[field.name]
    ) {
        messages.push(`The ${label} field confirmation does not match.`);
    }

    return messages;
}

/**
 * Validates the fields that are actually on screen.
 *
 * A field hidden by a condition is skipped, because the server skips it too:
 * `FormSchema` never builds a rule for a component that did not render, so
 * checking one here would block a submit the server would have accepted.
 *
 * Keyed the way Laravel keys its errors, so the same rendering path handles
 * both sources and a client message is replaced by the server's rather than
 * shown beside it.
 */
export function validateFields(
    fields: FieldDefinition[],
    values: FormValues,
): Record<string, string> {
    const errors: Record<string, string> = {};

    for (const field of fields) {
        if (!matchesConditions(field.conditions, values)) {
            continue;
        }

        const messages = fieldErrors(field, values);

        if (messages.length > 0) {
            errors[field.name] = messages[0];
        }
    }

    return errors;
}
