import { computed, onScopeDispose, watchEffect } from 'vue';
import type { ComputedRef } from 'vue';
import { useAppearance } from '@/composables/useAppearance';
import { usePanel } from '@/panel/composables/usePanel';

export type UsePanelStylingReturn = {
    /**
     * The panel's colours for the resolved appearance, as custom properties.
     *
     * Still returned for the shell to bind, so a layout that sets
     * `:style="themeStyle"` keeps working. The same map is also written to the
     * document element — see below for why both.
     */
    themeStyle: ComputedRef<Record<string, string>>;
    /**
     * The classes for one named part of the shell.
     *
     * Always includes the stable `panel-{name}` class, which is there whether
     * or not the panel said anything — that is what a stylesheet targets.
     * Anything the panel added for that hook follows it.
     */
    hook: (name: string) => string;
};

/**
 * Tokens the stylesheet reads under a different name than the config uses.
 *
 * `sidebar` is the surface a panel configures, and `bg-sidebar` resolves
 * `--sidebar-background`. Writing `--sidebar` therefore set a variable nothing
 * read, and the sidebar kept its default colour however the panel was
 * configured. The config key is the one an application already wrote, so it
 * stays; the alias is what makes it arrive.
 */
const ALIASES: Record<string, string> = {
    sidebar: 'sidebar-background',
};

/**
 * The two ways a panel changes how it looks without shipping a component.
 *
 * Both are read from the shared panel props, so they apply to every page of
 * the panel and to none outside it.
 */
export function usePanelStyling(): UsePanelStylingReturn {
    const { panel } = usePanel();
    const { resolvedAppearance } = useAppearance();

    /**
     * The palette for whichever appearance is actually resolved.
     *
     * Light and dark used to be merged rather than switched: only `theme.light`
     * was written, on the reasoning that the stylesheet's `.dark` block would
     * take over. It cannot. An inline custom property on the shell is more
     * specific than a `.dark` rule on an ancestor, so a panel that customised
     * its colours kept its *light* colours in dark mode, and only the
     * properties it had not customised went dark. The result was a half-dark
     * panel that no amount of stylesheet could correct.
     *
     * A missing dark value falls through to the package default rather than to
     * the panel's light value: a panel that customised its light primary and
     * said nothing about dark means "leave dark alone", and reusing the light
     * value there is how a bright accent ends up on a dark surface.
     */
    const themeStyle = computed<Record<string, string>>(() => {
        const theme = panel.value?.theme;

        if (theme === undefined) {
            return {};
        }

        const palette =
            (resolvedAppearance.value === 'dark' ? theme.dark : theme.light) ??
            {};

        const style: Record<string, string> = {};

        for (const [property, value] of Object.entries(palette)) {
            style[`--${ALIASES[property] ?? property}`] = value;
        }

        return style;
    });

    /**
     * Written to the document element, not only to the shell.
     *
     * Every overlay this panel opens — dialog, sheet, popover, select,
     * dropdown, tooltip, toast — is teleported to `body` by its Reka portal,
     * which is a sibling of the shell rather than a descendant. Custom
     * properties inherit, so a theme that lives on the shell reaches
     * everything drawn *inside* the shell and nothing drawn beside it: the
     * page took the panel's accent and its dialogs did not.
     *
     * The document element is the one node both are inside. Only the keys this
     * panel set are written, and they are removed when it goes away or names
     * different ones, so switching panels cannot leave the previous panel's
     * accent behind on an overlay.
     */
    if (typeof document !== 'undefined') {
        let applied: string[] = [];

        const clear = (): void => {
            for (const property of applied) {
                document.documentElement.style.removeProperty(property);
            }

            applied = [];
        };

        watchEffect(() => {
            const style = themeStyle.value;

            clear();

            for (const [property, value] of Object.entries(style)) {
                document.documentElement.style.setProperty(property, value);
                applied.push(property);
            }
        });

        onScopeDispose(clear);
    }

    function hook(name: string): string {
        const extra = panel.value?.cssHooks?.[name] ?? '';

        return extra === '' ? `panel-${name}` : `panel-${name} ${extra}`;
    }

    return { themeStyle, hook };
}
