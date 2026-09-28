import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * Package-owned user-facing copy must come from the dictionary.
 *
 * The earlier sweep parsed rendered *text nodes* and found none, which was
 * true and much narrower than it sounded. A string reaches a reader by more
 * routes than that: an `aria-label` a screen reader announces, a `title` a
 * tooltip shows, a `placeholder` in an empty field, an `alt` on an image —
 * and any of those can be a literal while every text node in the file is
 * already translated.
 *
 * This scans the rendered *positions*, statically. It is deliberately not a
 * dataflow analysis: proving that a particular string literal in `<script>`
 * ends up on screen would need one, and a guard that claimed to do it would
 * be claiming more than it does. What it does instead is check every place
 * copy is *bound* in a template, and require an explicit reason for each
 * literal that stays.
 *
 * Vendored `components/ui` is out of scope — those are shadcn-vue's files,
 * kept as upstream wrote them so an update is not a merge conflict about
 * whitespace. The panel's own components are the package's responsibility.
 */

/** Attributes whose value a person reads or hears. */
const COPY_ATTRIBUTES = [
    'aria-label',
    'aria-placeholder',
    'aria-roledescription',
    'title',
    'placeholder',
    'alt',
];

const ROOTS = [
    'resources/js/panel',
    'resources/js/pages',
    'resources/js/components',
];

/**
 * Literals that are not copy, each with the reason it is not.
 *
 * A new unexplained literal fails. The entries are exact strings rather than
 * patterns so that adding one is a decision somebody makes on purpose.
 */
const ALLOWED: Record<string, string> = {
    // Placeholders that are format hints rather than words: they read the
    // same in every language the package ships, and translating "HH" into
    // "JJ" would describe a field the user is not filling in.
    HH: 'A time format hint, identical in both shipped locales.',
    MM: 'A time format hint, identical in both shipped locales.',
    SS: 'A time format hint, identical in both shipped locales.',
};

function vueFiles(directory: string): string[] {
    const found: string[] = [];

    for (const entry of readdirSync(directory)) {
        const path = join(directory, entry);

        if (statSync(path).isDirectory()) {
            // Vendored from shadcn-vue; see the header.
            if (path.endsWith('components/ui') || entry === 'node_modules') {
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

/** The `<template>` block, with comments removed. */
function templateOf(source: string): string {
    const match = /<template>([\s\S]*)<\/template>/.exec(source);

    return match === null ? '' : match[1]!.replace(/<!--[\s\S]*?-->/g, '');
}

const FILES = ROOTS.flatMap((root) => vueFiles(root));

/** A word a reader would recognise as language, rather than a token. */
function looksLikeCopy(value: string): boolean {
    const trimmed = value.trim();

    if (trimmed === '' || trimmed in ALLOWED) {
        return false;
    }

    // At least one run of letters, and not a class list, path, or token.
    return (
        /[A-Za-z]{2,}/.test(trimmed) &&
        !/^[a-z0-9-]+$/.test(trimmed) &&
        !trimmed.includes('/') &&
        !trimmed.startsWith('#')
    );
}

describe('package-owned copy comes from the dictionary', () => {
    it('scans the components it is meant to be scanning', () => {
        // A guard that quietly scanned nothing would pass forever.
        expect(FILES.length).toBeGreaterThan(100);
    });

    it('binds no literal text node', () => {
        const offenders: string[] = [];
        const text = /love-nothing/;
        void text;

        for (const file of FILES) {
            const template = templateOf(readFileSync(file, 'utf8'));

            for (const match of template.matchAll(
                />\s*([A-Z][A-Za-z]+(?:\s+[A-Za-z']{2,}){1,8}[.?!]?)\s*</g,
            )) {
                if (looksLikeCopy(match[1] ?? '')) {
                    offenders.push(`${file}: "${match[1]}"`);
                }
            }
        }

        expect(offenders).toEqual([]);
    });

    it('binds no literal into an attribute a reader hears or sees', () => {
        const offenders: string[] = [];

        for (const file of FILES) {
            const template = templateOf(readFileSync(file, 'utf8'));

            for (const attribute of COPY_ATTRIBUTES) {
                // A static attribute: `aria-label="Close"`. The bound form
                // (`:aria-label="t(...)"`) is the correct one and is not
                // matched here.
                const pattern = new RegExp(
                    `(?<![:\\w-])${attribute}="([^"]*)"`,
                    'g',
                );

                for (const match of template.matchAll(pattern)) {
                    if (looksLikeCopy(match[1] ?? '')) {
                        offenders.push(`${file}: ${attribute}="${match[1]}"`);
                    }
                }
            }
        }

        expect(offenders).toEqual([]);
    });

    it('binds no English literal through a bound copy attribute', () => {
        const offenders: string[] = [];

        for (const file of FILES) {
            const template = templateOf(readFileSync(file, 'utf8'));

            for (const attribute of COPY_ATTRIBUTES) {
                // `:aria-label="'Close'"` and `` :title="`Remove ${x}`" `` —
                // bound, and still a literal. This is the shape that escaped
                // the earlier sweep entirely.
                const pattern = new RegExp(
                    `:${attribute}="\\s*(['\`])([^'\`]*)\\1`,
                    'g',
                );

                for (const match of template.matchAll(pattern)) {
                    if (looksLikeCopy(match[2] ?? '')) {
                        offenders.push(
                            `${file}: :${attribute}="${match[1]}${match[2]}${match[1]}"`,
                        );
                    }
                }
            }
        }

        expect(offenders).toEqual([]);
    });

    it('keeps the allowlist free of entries nothing uses', () => {
        const used = new Set<string>();

        for (const file of FILES) {
            const template = templateOf(readFileSync(file, 'utf8'));

            for (const key of Object.keys(ALLOWED)) {
                if (template.includes(`"${key}"`)) {
                    used.add(key);
                }
            }
        }

        // A stale entry is a reason nobody has to justify any more, and a
        // place a future literal could hide behind an approval it never got.
        expect(Object.keys(ALLOWED).filter((key) => !used.has(key))).toEqual(
            [],
        );
    });
});
