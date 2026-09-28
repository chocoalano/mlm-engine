import type { Component } from 'vue';

type EmptyStateLoader = () => Promise<{ default: Component }>;

/**
 * Resolves a table's own empty-state component.
 *
 * A build-time allowlist, exactly as it is for custom columns and widgets:
 * only components under `resources/js/pages/Panels/**\/EmptyStates/` are in
 * the bundle, so a name that was not compiled in cannot be reached however it
 * arrives. The pattern is relative, not the `@` alias — Vite's dev server
 * resolves an aliased glob to nothing while the build resolves it normally.
 */
const modules = import.meta.glob<{ default: Component }>(
    '../../pages/Panels/**/EmptyStates/*.vue',
);

const PAGES_SEGMENT = '/pages/';

const componentsByName: Record<string, EmptyStateLoader> = Object.fromEntries(
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

/**
 * Null for an unknown name. The table then draws its ordinary empty state
 * rather than nothing at all.
 */
export function resolveEmptyStateComponent(
    name: string,
): EmptyStateLoader | null {
    return componentsByName[name] ?? null;
}
