/**
 * What a time of day is, and whether it is inside its bounds.
 *
 * One module because two answers to the same question drift. `PanelTimePicker`
 * decides which *options* to disable and `DateTimeField` decides whether a
 * time it already holds is still legal after the date moved — different jobs,
 * but both of them are "is this time within these bounds", and a bound that
 * greys out 09:29 while a sibling accepts it is worse than either behaviour
 * on its own.
 *
 * Everything here is string-and-number arithmetic on a wall clock. Nothing
 * constructs a `Date`, which is deliberate: a `Date` carries a timezone, and
 * a time of day does not. `09:30` is 09:30 wherever the reader is sitting.
 */

export interface TimeParts {
    hour: number;
    minute: number;
    second: number;
}

/**
 * Narrowed rather than trusted.
 *
 * These values arrive as JSON from a server payload, and a column holding
 * something that is not a time must leave a control empty rather than throw
 * inside a renderer. Out-of-range parts are rejected too — `25:99` parses as
 * digits and is not a time.
 */
export function parseTime(value: string | null | undefined): TimeParts | null {
    if (typeof value !== 'string') {
        return null;
    }

    const match = /^(\d{2}):(\d{2})(?::(\d{2}))?$/.exec(value.trim());

    if (match === null) {
        return null;
    }

    const hour = Number(match[1]);
    const minute = Number(match[2]);
    const second = match[3] === undefined ? 0 : Number(match[3]);

    if (hour > 23 || minute > 59 || second > 59) {
        return null;
    }

    return { hour, minute, second };
}

/** Seconds since midnight, which is what makes two times comparable. */
export function secondsOfDay(parts: TimeParts): number {
    return parts.hour * 3600 + parts.minute * 60 + parts.second;
}

/**
 * Orders two times, treating an absent seconds part as `:00`.
 *
 * `09:30` against `09:30:00` has to mean equal. Comparing the strings would
 * make the shorter one smaller, which would put a field holding `09:30` just
 * outside a bound of `09:30:00`.
 */
export function compareTime(a: string, b: string): number {
    const left = parseTime(a);
    const right = parseTime(b);

    if (left === null || right === null) {
        return 0;
    }

    return secondsOfDay(left) - secondsOfDay(right);
}

/**
 * Whether a time is inside an inclusive range.
 *
 * A null bound is no bound. Both bounds are inclusive because the field's
 * validation rules are `after_or_equal` and `before_or_equal` — the exact
 * boundary value is legal, and a control that refused it would disagree with
 * the server that accepts it.
 */
export function timeWithinBounds(
    value: string,
    min: string | null,
    max: string | null,
): boolean {
    if (min !== null && compareTime(value, min) < 0) {
        return false;
    }

    return !(max !== null && compareTime(value, max) > 0);
}
