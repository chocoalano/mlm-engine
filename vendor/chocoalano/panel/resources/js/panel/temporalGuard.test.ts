import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * A source guard against native temporal controls coming back.
 *
 * The runtime half of this lives in `forms/temporalControls.test.ts` and in
 * `tests/browser/temporal.mjs`: both mount the renderers and assert that no
 * `input[type=date|time|datetime|datetime-local]` reaches the DOM. This half
 * reads the source, because the two catch different mistakes and the audit
 * proved it.
 *
 * A literal `type="date"` is what a person greps for. The violation that
 * survived every such search was `:type="inputTypeFor(rule)"` in the query
 * builder, which resolved to `date` at runtime because `DateConstraint`
 * declares its input as `date` — a semantic type the server is right to send
 * and the renderer was wrong to hand to an `<input type>`. Nothing about that
 * line contains the word "date".
 *
 * So a dynamic `:type` is not banned — `TextInputField` and the password
 * toggle need one — it is *enumerated*. A binding that nobody has reviewed
 * fails here, which is the only way this class of defect gets caught at the
 * moment it is written rather than three releases later.
 */

/** The controls the package's own renderers must never create. */
const FORBIDDEN_TYPES = ['date', 'time', 'datetime', 'datetime-local'];

const ROOTS = ['resources/js', 'frontend'];

function vueFiles(directory: string): string[] {
    const found: string[] = [];

    for (const entry of readdirSync(directory)) {
        const path = join(directory, entry);

        if (statSync(path).isDirectory()) {
            if (entry === 'node_modules') {
                continue;
            }

            found.push(...vueFiles(path));

            continue;
        }

        if (path.endsWith('.vue')) {
            found.push(path);
        }
    }

    return found;
}

/**
 * Source with its commentary removed.
 *
 * `PanelDatePicker` explains that it replaces `<input type="date">`, and
 * `DateTimeField` says what it used to be. Those sentences are the reason the
 * components exist; counting them as violations would mean the fix could not
 * describe itself.
 */
function withoutComments(source: string): string {
    return source
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/^\s*\/\/.*$/gm, '');
}

const FILES = ROOTS.flatMap((root) => vueFiles(root));

/**
 * Every dynamic `:type` on a control, and why it is allowed.
 *
 * Adding a binding here is a decision to make: the question each entry has to
 * answer is what the expression can evaluate to, and whether any of those
 * values is a temporal one.
 */
const REVIEWED_TYPE_BINDINGS: Record<string, string> = {
    "resources/js/components/PasswordInput.vue :: showPassword ? 'text' : 'password'":
        'A closed expression over two literals, neither temporal.',
    'resources/js/panel/tables/DataTableQueryBuilder.vue :: inputTypeFor(rule)':
        'Date constraints are routed to PanelDatePicker before this renders, ' +
        'so only text and number reach the input. This is DT03.',
    'resources/js/panel/tables/DataTableCell.vue :: column.inputType':
        'TextInputColumn serializes text or number only — see its toArray().',
    'resources/js/panel/forms/fields/TextInputField.vue :: field.inputType':
        'TextInput serializes text or email only — see its toArray().',
};

describe('no package renderer can create a native temporal input', () => {
    it('finds the Vue sources it is meant to be scanning', () => {
        // A guard that silently scanned nothing would pass forever.
        expect(FILES.length).toBeGreaterThan(200);
    });

    it('declares no literal temporal input type', () => {
        const offenders: string[] = [];

        for (const file of FILES) {
            const source = withoutComments(readFileSync(file, 'utf8'));

            for (const type of FORBIDDEN_TYPES) {
                if (
                    new RegExp(`\\btype\\s*=\\s*["']${type}["']`).test(source)
                ) {
                    offenders.push(`${file} :: type="${type}"`);
                }
            }
        }

        expect(offenders).toEqual([]);
    });

    it('binds no temporal literal through :type', () => {
        const offenders: string[] = [];

        for (const file of FILES) {
            const source = withoutComments(readFileSync(file, 'utf8'));

            for (const match of source.matchAll(/:type\s*=\s*"([^"]*)"/g)) {
                const expression = match[1] ?? '';

                if (
                    FORBIDDEN_TYPES.some((type) =>
                        new RegExp(`['"\`]${type}['"\`]`).test(expression),
                    )
                ) {
                    offenders.push(`${file} :: ${expression}`);
                }
            }
        }

        expect(offenders).toEqual([]);
    });

    it('has a reviewed reason for every dynamic :type binding', () => {
        const found: string[] = [];

        for (const file of FILES) {
            const source = withoutComments(readFileSync(file, 'utf8'));

            for (const match of source.matchAll(/:type\s*=\s*"([^"]*)"/g)) {
                found.push(`${file} :: ${(match[1] ?? '').trim()}`);
            }
        }

        // A binding nobody has thought about is exactly how DT03 shipped. The
        // failure message names the file and the expression, so reviewing it
        // is a matter of answering one question: what can this evaluate to?
        expect(
            found.filter((entry) => !(entry in REVIEWED_TYPE_BINDINGS)),
        ).toEqual([]);
    });

    it('keeps the reviewed list free of bindings that no longer exist', () => {
        const found = new Set<string>();

        for (const file of FILES) {
            const source = withoutComments(readFileSync(file, 'utf8'));

            for (const match of source.matchAll(/:type\s*=\s*"([^"]*)"/g)) {
                found.add(`${file} :: ${(match[1] ?? '').trim()}`);
            }
        }

        // A stale entry is a reason nobody has to justify any more, and a
        // place a future binding could hide behind an approval it never got.
        expect(
            Object.keys(REVIEWED_TYPE_BINDINGS).filter(
                (entry) => !found.has(entry),
            ),
        ).toEqual([]);
    });
});
