import type { Component } from 'vue';

type HookLoader = () => Promise<{ default: Component }>;

/**
 * Resolves a render hook's Vue component.
 *
 * The same build-time allowlist the widget registry is: a name that was not
 * compiled in cannot be reached however it arrives. The pattern is relative
 * rather than aliased, because Vite's dev server resolves an aliased glob to
 * nothing while the build resolves it normally.
 */
const modules = import.meta.glob<{ default: Component }>(
    '../../pages/Panels/**/Hooks/*.vue',
);

const PAGES_SEGMENT = '/pages/';

const componentsByName: Record<string, HookLoader> = Object.fromEntries(
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

export function hasHookComponent(name: string): boolean {
    return name in componentsByName;
}

/**
 * Null for an unknown name. The caller renders nothing: a decorative
 * injection must never be able to break the page it decorates.
 */
export function resolveHookComponent(name: string): HookLoader | null {
    return componentsByName[name] ?? null;
}
