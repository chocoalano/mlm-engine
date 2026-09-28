<?php

declare(strict_types=1);

namespace PandaPanel\Support;

/**
 * Extra classes a panel hangs on named parts of the shell.
 *
 * Every part the framework renders already carries a stable class —
 * `panel-topbar`, `panel-table-row`, and so on — written into the Vue
 * components rather than generated. That is what a stylesheet targets, and it
 * is there whether or not a panel says anything.
 *
 * This adds to them per panel. Two panels sharing a build can then look
 * different without either one shipping a component of its own, which is the
 * cheapest kind of theming there is.
 *
 * Names are an allowlist for the same reason `RenderHook` is a closed enum: a
 * class registered against a part the shell does not render would silently do
 * nothing, and finding out why is a bad afternoon.
 */
final class CssHooks
{
    /**
     * The parts a panel may target.
     *
     * Each name is a class the shell actually emits. Adding one here means
     * adding `panel-{name}` to the component that draws it — the test in
     * `CssHookTest` fails otherwise, which is the point.
     *
     * @var list<string>
     */
    public const HOOKS = [
        'shell',
        'sidebar',
        'topbar',
        'page',
        'page-header',
        'table',
        'table-row',
        'form',
        'infolist',
        'widget',
        'modal',
    ];

    /** @var array<string, string> */
    private array $classes = [];

    /**
     * @param  array<string, string>  $classes  keyed by hook name
     */
    public function add(array $classes): self
    {
        foreach ($classes as $hook => $class) {
            if (! in_array($hook, self::HOOKS, true)) {
                continue;
            }

            // Appended rather than replaced: two calls that both target the
            // topbar both meant it.
            $this->classes[$hook] = trim(($this->classes[$hook] ?? '').' '.$class);
        }

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->classes;
    }
}
