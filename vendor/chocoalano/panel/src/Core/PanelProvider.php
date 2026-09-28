<?php

declare(strict_types=1);

namespace PandaPanel\Core;

use Illuminate\Support\Str;

/**
 * Base class for application panel definitions.
 *
 * Subclasses configure a panel and nothing else. Keep service resolution out
 * of `panel()`: it runs during provider boot, before the container is fully
 * warm for request-scoped bindings.
 */
abstract class PanelProvider
{
    abstract public function panel(Panel $panel): Panel;

    /**
     * The panel id, derived from the class name unless overridden.
     *
     * `AdminPanelProvider` becomes `admin`.
     */
    public function panelId(): string
    {
        return Str::of(class_basename(static::class))
            ->beforeLast('PanelProvider')
            ->kebab()
            ->toString();
    }

    /**
     * Build the configured panel. The id is seeded from `panelId()` so a
     * provider only sets it explicitly when it wants a different one.
     */
    public function build(): Panel
    {
        return $this->panel(Panel::make($this->panelId()));
    }
}
