<?php

declare(strict_types=1);

/*
 * How a number and a date are written here.
 *
 * These are separators and format strings rather than sentences, and they are
 * in `lang/` for the same reason the sentences are: which of `1,234.56` and
 * `1.234,56` is right is a fact about the locale, and a panel that switched
 * language and kept English grouping would be half-translated in the place
 * numbers are hardest to misread quietly.
 *
 * Not `Illuminate\Support\Number`, which would do this properly through ICU —
 * it calls `ensureIntlExtensionIsInstalled()`, and this package requires only
 * `ext-json` and `ext-zip`. Making `ext-intl` a hard requirement of an admin
 * panel is a real install barrier on shared hosting, and a two-key table gets
 * the grouping right for every locale anybody has asked for.
 *
 * The date formats are `date()` format strings, and they are defaults: a
 * column or entry that calls `->format()` says what it wants and is never
 * overridden from here.
 */

return [
    'decimal_separator' => '.',
    'thousands_separator' => ',',

    /** `DateColumn`, and the date half of anything that shows one. */
    'date' => 'M j, Y',

    /** `DateTimeColumn` — a table cell, so 24-hour and no space wasted. */
    'date_time' => 'M j, Y H:i',

    /** `DateTimeEntry`, which has a whole row to itself. */
    'date_time_verbose' => 'M j, Y g:ia',

    /** The filter chips, where the space for a date is one line of a button. */
    'date_compact' => 'j M Y',
];
