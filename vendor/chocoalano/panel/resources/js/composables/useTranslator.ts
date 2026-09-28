import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';

/**
 * The strings the panel's own components draw themselves with.
 *
 * `SharePanelData` puts one group — `lang/{locale}/frontend.php` — on the
 * page as `translations`, and this reads it. Everything else in `lang/` is
 * read in PHP and never crosses the wire.
 *
 * No `vue-i18n`. These components are *published* into an application, so a
 * runtime dependency here is a line every application has to add to its own
 * `package.json` and keep in step with this package's — for a lookup that is
 * thirty lines. The cost of the dependency is larger than the thing it
 * replaces.
 *
 *     const { t } = useTranslator();
 *
 *     t('tables.rows_per_page')                        // "Rows per page"
 *     t('tables.search_column', { column: 'Email' })   // "Search Email"
 *
 * Schema labels are not here. A column header, a field label, an action's
 * button — those are resolved on the server and arrive already translated,
 * because that is where the schema lives.
 */

/** What `SharePanelData` shares. Nested exactly as the PHP file is written. */
type Dictionary = Record<string, unknown>;

/**
 * The last segment of a key, as words.
 *
 * What `t()` falls back to when a key is missing: `ui.close` reads "Close"
 * and `tables.rows_per_page` reads "Rows per page", which is wrong in an
 * Indonesian panel but is a word rather than `tables.rows_per_page` in the
 * middle of a sentence. The keys are named after their English text, so this
 * degrades to approximately the source language.
 *
 * A missing key is a bug, and it is caught where bugs belong: a test asserts
 * that every key the components ask for exists in every locale the package
 * ships. This is what happens if one gets through anyway.
 */
function humanize(key: string): string {
    const last = key.slice(key.lastIndexOf('.') + 1).replace(/_/g, ' ');

    return last.charAt(0).toUpperCase() + last.slice(1);
}

function walk(dictionary: Dictionary, key: string): string | null {
    let node: unknown = dictionary;

    for (const segment of key.split('.')) {
        if (typeof node !== 'object' || node === null) {
            return null;
        }

        node = (node as Dictionary)[segment];
    }

    return typeof node === 'string' ? node : null;
}

export interface Translator {
    /**
     * The string for `key`, with `:name` placeholders replaced.
     *
     * Laravel's own placeholder syntax, so a line reads the same in the PHP
     * file whether it is rendered here or there.
     */
    t: (key: string, replacements?: Record<string, string | number>) => string;
    /** The locale the server resolved, for `Intl` and for `<html lang>`. */
    locale: ComputedRef<string>;
}

export function useTranslator(): Translator {
    const page = usePage();

    // Computed rather than read once: the props change under a client-side
    // navigation, and a dictionary snapshotted at setup would leave a panel
    // that switched locale showing the language it was mounted in.
    const dictionary = computed<Dictionary>(() => {
        const shared = (page.props as Record<string, unknown>).translations;

        return typeof shared === 'object' && shared !== null
            ? (shared as Dictionary)
            : {};
    });

    const locale = computed<string>(() => {
        const shared = (page.props as Record<string, unknown>).locale;

        return typeof shared === 'string' && shared !== '' ? shared : 'en';
    });

    function t(
        key: string,
        replacements?: Record<string, string | number>,
    ): string {
        let line = walk(dictionary.value, key) ?? humanize(key);

        if (replacements) {
            for (const [name, value] of Object.entries(replacements)) {
                line = line.replace(`:${name}`, String(value));
            }
        }

        return line;
    }

    return { t, locale };
}
