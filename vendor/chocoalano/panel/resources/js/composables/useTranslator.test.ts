import { describe, expect, it, vi } from 'vitest';
import { useTranslator } from './useTranslator';

/**
 * The lookup, without a page.
 *
 * `usePage()` is Inertia's module-level store, so it is mocked here rather
 * than mounting an app: what these cover is the three decisions this module
 * actually makes — how a nested key is walked, what happens when it is not
 * there, and how `:name` is replaced.
 */
const props = vi.hoisted(() => ({ value: {} as Record<string, unknown> }));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        get props() {
            return props.value;
        },
    }),
}));

function withProps(shared: Record<string, unknown>) {
    props.value = shared;

    return useTranslator();
}

describe('useTranslator', () => {
    it('walks a nested key', () => {
        const { t } = withProps({
            translations: { tables: { rows_per_page: 'Baris per halaman' } },
        });

        expect(t('tables.rows_per_page')).toBe('Baris per halaman');
    });

    it('humanizes the last segment of a key nothing defines', () => {
        // Not the raw key: a missing key is a bug caught by the PHP suite,
        // and what a reader gets in the meantime should be a word rather
        // than `tables.rows_per_page` in the middle of a sentence.
        const { t } = withProps({ translations: {} });

        expect(t('tables.rows_per_page')).toBe('Rows per page');
        expect(t('ui.close')).toBe('Close');
    });

    it('degrades the same way when the page shares nothing at all', () => {
        // An application whose middleware never ran, or a component rendered
        // outside a panel response.
        const { t } = withProps({});

        expect(t('ui.close')).toBe('Close');
    });

    it('refuses a key that resolves to a group rather than a line', () => {
        const { t } = withProps({ translations: { ui: { close: 'Tutup' } } });

        expect(t('ui')).toBe('Ui');
    });

    it('replaces Laravel-style placeholders', () => {
        const { t } = withProps({
            translations: { tables: { range: ':from–:to of :total' } },
        });

        expect(t('tables.range', { from: 1, to: 25, total: 300 })).toBe(
            '1–25 of 300',
        );
    });

    it('replaces a placeholder in a humanized fallback too', () => {
        const { t } = withProps({ translations: {} });

        expect(t('tables.search_column', { column: 'Email' })).toBe(
            'Search column',
        );
    });

    it('reads the locale the server resolved, and defaults to English', () => {
        expect(withProps({ locale: 'id' }).locale.value).toBe('id');
        expect(withProps({}).locale.value).toBe('en');
        expect(withProps({ locale: '' }).locale.value).toBe('en');
    });
});
