import type { Component } from 'vue';

type ComponentLoader = () => Promise<{ default: Component }>;

/**
 * Resolves a custom field, layout, entry, or modal's Vue component.
 *
 * The globs are a build-time allowlist, exactly as they are for custom
 * columns and widgets: only components under `resources/js/pages/Panels/**\/
 * {Fields,Schemas,Entries,Modals}/` are in the bundle, so a name that was not
 * compiled in cannot be reached however it arrives. The name always
 * originates from a registered PHP component, never from request input, and
 * this lookup is the second lock rather than the first.
 *
 * One registry for all four rather than four that would say the same thing:
 * what they have in common is that a *name* resolves to a component the build
 * saw, and where that name was declared changes nothing about the rule.
 *
 * The patterns are relative, not the `@` alias. Vite's dev server resolves an
 * aliased glob to nothing at all while the production build resolves it
 * normally — an aliased pattern means every custom component renders the
 * fallback in development and works once built.
 */
const modules = {
    ...import.meta.glob<{ default: Component }>(
        '../../pages/Panels/**/Fields/*.vue',
    ),
    ...import.meta.glob<{ default: Component }>(
        '../../pages/Panels/**/Schemas/*.vue',
    ),
    ...import.meta.glob<{ default: Component }>(
        '../../pages/Panels/**/Entries/*.vue',
    ),
    ...import.meta.glob<{ default: Component }>(
        '../../pages/Panels/**/Modals/*.vue',
    ),
};

/**
 * Keyed by the name PHP sends — the path below `pages/`, without extension.
 *
 * Derived from the real paths rather than reconstructed from the name:
 * Vite's key format follows the pattern as written and differs between the
 * dev server and the build, and a mismatch fails silently as a component
 * that renders nothing forever.
 */
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

/** Names already complained about, so one typo is one warning. */
const missing = new Set<string>();

/**
 * Says so, once, when a name resolves to nothing.
 *
 * The glob is a build-time allowlist, so an unregistered name is not a
 * security question — it cannot reach anything. It is a *findability*
 * question: the fallback looks exactly like a component that rendered
 * nothing, and the three reasons for it (a typo, a file outside the globbed
 * directory, a build that has not been re-run) are indistinguishable from the
 * screen.
 *
 * Development only. In production the fallback is the whole answer, because
 * this is a build problem and a console message on a live panel helps nobody.
 */
function reportMissing(kind: string, name: string, directory: string): void {
    if (!import.meta.env.DEV || missing.has(name)) {
        return;
    }

    missing.add(name);

    console.warn(
        '[panel] The ' +
            kind +
            ' component [' +
            name +
            '] is not in the build-time registry, so a fallback is drawn instead. ' +
            'It has to live under ' +
            directory +
            ' — check the path and the spelling, then rebuild.',
    );
}

export function hasFormComponent(name: string): boolean {
    return name in componentsByName;
}

/**
 * Null for an unknown name. The renderer then shows its placeholder rather
 * than throwing, so one mistyped component cannot take a form down — and the
 * fields around it stay editable.
 */
export function resolveFormComponent(name: string): ComponentLoader | null {
    const loader = componentsByName[name] ?? null;

    if (loader === null) {
        reportMissing('form', name, 'resources/js/pages/Panels/{Panel}/');
    }

    return loader;
}
