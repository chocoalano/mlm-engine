<?php

declare(strict_types=1);

namespace PandaPanel\Core;

use PandaPanel\Contracts\WidgetContract;
use PandaPanel\Exceptions\PanelRegistrationException;

/**
 * Panel-scoped widget lookup, keyed by widget id.
 *
 * Dashboard ordering is resolved here because it is static metadata. Widget
 * data and authorization stay per-request and are never held by a registry.
 */
final class WidgetRegistry
{
    /** @var array<string, class-string<WidgetContract>> */
    private array $widgets = [];

    /**
     * @param  class-string<WidgetContract>  $widget
     */
    public function register(string $widget): void
    {
        $id = $widget::id();

        if (isset($this->widgets[$id]) && $this->widgets[$id] !== $widget) {
            throw PanelRegistrationException::duplicateWidgetId($id, $this->widgets[$id], $widget);
        }

        $this->widgets[$id] = $widget;
    }

    public function has(string $id): bool
    {
        return isset($this->widgets[$id]);
    }

    /**
     * @return class-string<WidgetContract>|null
     */
    public function byId(string $id): ?string
    {
        return $this->widgets[$id] ?? null;
    }

    /**
     * @return list<class-string<WidgetContract>>
     */
    public function all(): array
    {
        $widgets = array_values($this->widgets);
        sort($widgets);

        return $widgets;
    }

    public function count(): int
    {
        return count($this->widgets);
    }
}
