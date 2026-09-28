import type { Component } from 'vue';

type ColumnLoader = () => Promise<{ default: Component }>;

/**
 * Resolves a custom column's Vue component.
 *
 * The glob is a build-time allowlist, exactly as it is for custom widgets:
 * only components under `resources/js/pages/Panels/**\/Columns/` are in the
 * bundle, so a name that was not compiled in cannot be reached however it
 * arrives. The name always originates from a registered PHP column, never
 * from request input, and this lookup is the second lock rather than the
 * first.
 *
 * The pattern is relative, not the `@` alias. Vite's dev server resolves an
 * aliased glob to nothing at all, while the production build resolves it
 * normally — an aliased pattern means every custom column renders the
 * fallback in development and works once built.
 */
const modules = import.meta.glob<{ default: Component }>(
    '../../pages/Panels/**/Columns/*.vue',
);

/**
 * Keyed by the name PHP sends — the path below `pages/`, without extension.
 *
 * Derived from the real paths rather than reconstructed from the name:
 * Vite's key format follows the pattern as written and differs between the
 * dev server and the build, and a mismatch fails silently as a column that
 * renders nothing forever.
 */
const PAGES_SEGMENT = '/pages/';

const componentsByName: Record<string, ColumnLoader> = Object.fromEntries(
    Object.entries(modules)
        .filter(([path]) => path.includes(PAGES_SEGMENT))
        .map(([path, loader]) => [
            path.slice(
                path.indexOf(PAGES_SEGMENT) + PAGES_SEGMENT.length,
                -'.vue'.length,
            ),
            loader,
        ]),
);

export function hasColumnComponent(name: string): boolean {
    return name in componentsByName;
}

/**
 * Null for an unknown name. The cell then renders its placeholder rather
 * than throwing, so one mistyped component cannot take a table down.
 */
export function resolveColumnComponent(name: string): ColumnLoader | null {
    return componentsByName[name] ?? null;
}
