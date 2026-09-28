/**
 * @vitest-environment happy-dom
 */
import { execSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

/**
 * Two things that are easy to get wrong once and never notice again.
 *
 * The first is a string the package puts on screen in English regardless of
 * locale. The second is subtler and is why U19 could not be closed by
 * translating a list: `Search ${field.label.toLowerCase()}...` builds a
 * sentence in Vue out of a label the *server* already translated. Even if the
 * word "Search" were localized, lower-casing a translated noun is not a
 * localization strategy — it is an English typographic habit applied to
 * languages that do not share it, and in some it changes the word.
 *
 * These are source assertions on purpose. What is being asserted is that a
 * literal is *absent*, and absence is not something a mounted component can
 * demonstrate.
 */
function read(path: string): string {
    return readFileSync(path, 'utf8');
}

const EN = read('lang/en/frontend.php');
const ID = read('lang/id/frontend.php');

/** `'key' => 'value',` — the only shape these files use. */
function keys(file: string): Set<string> {
    return new Set(
        [...file.matchAll(/^\s{8}'([a-z0-9_]+)' =>/gm)].map(
            (match) => match[1],
        ),
    );
}

/** `:name` placeholders, which both locales have to agree about. */
function placeholders(file: string, key: string): string[] {
    const line = file
        .split('\n')
        .find((candidate) => candidate.trim().startsWith(`'${key}' =>`));

    return [...(line ?? '').matchAll(/:([a-z]+)/g)]
        .map((match) => match[1])
        .sort();
}

/*
 * T32 / T33 / T34
 */

describe('the strings this wave added', () => {
    const added = [
        'sort_by',
        'reorder_row',
        'reorder_announcement',
        'move_up',
        'move_down',
        'select_row',
        'per_page',
        'page_of',
        'remove_filter',
        'filter_from',
        'filter_to',
        'rule_value',
        'remove_rule',
        'rule',
        'search_field_placeholder',
        'select_more',
        'remove_file',
        'move_item_up',
        'move_item_down',
        'remove_item',
        'move_column',
        'confirm_field',
        'create_record',
    ];

    it('exist in English', () => {
        const defined = keys(EN);

        expect(added.filter((key) => !defined.has(key))).toEqual([]);
    });

    it('exist in Indonesian', () => {
        const defined = keys(ID);

        expect(added.filter((key) => !defined.has(key))).toEqual([]);
    });

    it('take the same parameters in both', () => {
        const disagreeing = added.filter(
            (key) =>
                placeholders(EN, key).join(',') !==
                placeholders(ID, key).join(','),
        );

        // A key whose locales disagree about `:count` renders the literal
        // `:count` to half the users and nothing to the other half.
        expect(disagreeing).toEqual([]);
    });
});

/*
 * T28 / T29 / T30 / T31 / T35
 */

describe('package-owned copy', () => {
    it('no longer counts pages in English', () => {
        const source = read(
            'resources/js/panel/tables/DataTablePagination.vue',
        );

        expect(source).not.toContain('/ page');
        expect(source).not.toMatch(/Page \{\{/);
        expect(source).toContain("t('tables.per_page'");
        expect(source).toContain("t('tables.page_of'");
    });

    it('never re-cases a label the server translated', () => {
        // The whole of the U19 locale-safety point, asserted across every
        // shipped component rather than the two that were reported.
        // Every shipped component and page, not a hand-listed set: the first
        // version of this test named four files and missed a fifth, which is
        // exactly how the defect survived being reported once already.
        const offenders = execSync(
            "grep -rl 'label.toLowerCase()' resources/js --include='*.vue' || true",
        )
            .toString()
            .split('\n')
            .filter((path) => path !== '');

        expect(offenders).toEqual([]);
    });

    it('asks for the field label untouched', () => {
        const source = read('resources/js/panel/forms/fields/SelectField.vue');

        expect(source).toContain("t('forms.search_field_placeholder'");
        expect(source).toContain('field: field.label');
    });

    it('names a removed file through a template', () => {
        const source = read(
            'resources/js/panel/forms/fields/FileUploadField.vue',
        );

        expect(source).toContain("t('forms.remove_file'");
        expect(source).not.toContain('`Remove ${');
    });

    it('leaves no audited literal behind', () => {
        // The candidates found by the sweep, each now a template. A regex over
        // every string in the tree would fail on CSS classes and role names;
        // this names what was actually audited.
        const audited: Array<[string, RegExp]> = [
            ['resources/js/panel/tables/DataTable.vue', /`Sort by \$\{/],
            ['resources/js/panel/tables/DataTable.vue', /`Select row \$\{/],
            ['resources/js/panel/tables/DataTable.vue', /`Reorder row \$\{/],
            [
                'resources/js/panel/tables/DataTableCard.vue',
                /`Select record \$\{/,
            ],
            ['resources/js/panel/tables/DataTableToolbar.vue', /`Remove \$\{/],
            [
                'resources/js/panel/tables/DataTableFilters.vue',
                /`\$\{filter\.label\}/,
            ],
            [
                'resources/js/panel/tables/DataTableQueryBuilder.vue',
                /`Value for \$\{/,
            ],
            [
                'resources/js/panel/tables/DataTableQueryBuilder.vue',
                /`Remove rule \$\{/,
            ],
            [
                'resources/js/panel/forms/fields/KeyValueField.vue',
                /`Remove \$\{/,
            ],
            ['resources/js/panel/forms/fields/RepeaterField.vue', /`Move \$\{/],
            [
                'resources/js/panel/forms/fields/RepeaterField.vue',
                /`Remove \$\{/,
            ],
        ];

        const remaining = audited
            .filter(([path, pattern]) => pattern.test(read(path)))
            .map(([path, pattern]) => `${path} ${pattern}`);

        expect(remaining).toEqual([]);
    });
});

/*
 * U16 — T21 / T22 / T23 / T24 / T25 / T26 / T27
 *
 * happy-dom resolves no Tailwind breakpoint and computes no layout, so what
 * can be asserted is the structure that decides the layout. Whether anything
 * overlaps at 320px is browser behaviour and is not claimed.
 */

describe('rows of actions', () => {
    it('lets the form action row wrap', () => {
        const source = read('resources/js/panel/forms/FormRenderer.vue');

        // Three buttons with Indonesian labels — "Simpan & buat lainnya" —
        // pushed a `flex items-center` row past the viewport, and a sticky bar
        // cannot be scrolled sideways to reach what fell off it.
        expect(source).toContain('flex flex-wrap items-center gap-2');
        expect(source).not.toMatch(
            /class="flex items-center gap-2"\n\s+:class="\n\s+stickyActions/,
        );
    });

    it('keeps the submit button the primary one', () => {
        const source = read('resources/js/panel/forms/FormRenderer.vue');

        // The hierarchy is carried by the variants and survives wrapping.
        // Making all three full-width blocks would give Cancel the weight of
        // Save.
        expect(source).toMatch(/<Button type="submit"/);
        expect(source).toMatch(/variant="outline"/);
        expect(source).toMatch(/variant="ghost"/);
    });

    it('lets the page header wrap its actions and keep its title', () => {
        const source = read('resources/js/panel/components/PageHeader.vue');

        // The title was `truncate` at every width so the actions could stay on
        // one line. That is the wrong way round: the title says which record
        // is on screen, and the actions can wrap.
        expect(source).not.toContain('class="truncate text-xl');
        expect(source).toContain('break-words');
        expect(source).toContain('flex flex-wrap items-center gap-2');
    });
});
