import type { Component } from 'vue';

type ComponentLoader = () => Promise<{ default: Component }>;

/**
 * Resolves a replacement sidebar or topbar.
 *
 * The glob is a build-time allowlist, exactly as it is for custom columns,
 * fields, and widgets: only components under
 * `resources/js/pages/Panels/**\/Shell/` are in the bundle, so a name that
 * was not compiled in cannot be reached however it arrives. The name always
 * originates from a panel's own configuration, never from request input.
 *
 * The pattern is relative, not the `@` alias. Vite's dev server resolves an
 * aliased glob to nothing at all while the production build resolves it
 * normally — an aliased pattern means every replacement falls back in
 * development and works once built.
 */
const modules = import.meta.glob<{ default: Component }>(
    '../../pages/Panels/**/Shell/*.vue',
);

const PAGES_SEGMENT = '/pages/';

const componentsByName: Record<string, ComponentLoader> = Object.fromEntries(
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
 * Null for an unknown name. The shell then keeps its built-in bar rather
 * than leaving the page with no navigation at all — a mistyped component
 * must not be able to strand somebody on a page they cannot leave.
 */
export function resolveShellComponent(name: string): ComponentLoader | null {
    return componentsByName[name] ?? null;
}
