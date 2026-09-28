<?php

declare(strict_types=1);

namespace PandaPanel\Core;

use PandaPanel\Exceptions\PanelRegistrationException;

/**
 * Holds every registered panel and rejects ambiguous registrations.
 *
 * Duplicate ids and duplicate path/domain pairs are developer errors that
 * would otherwise surface as a route silently shadowing another one.
 */
final class PanelRegistry
{
    /** @var array<string, Panel> */
    private array $panels = [];

    public function register(Panel $panel): void
    {
        $id = $panel->getId();

        if (isset($this->panels[$id])) {
            throw PanelRegistrationException::duplicatePanelId($id);
        }

        foreach ($this->panels as $existing) {
            if ($existing->getPath() === $panel->getPath() && $existing->getDomain() === $panel->getDomain()) {
                throw PanelRegistrationException::duplicatePanelPath(
                    $panel->getPath(),
                    $panel->getDomain(),
                    $existing->getId(),
                );
            }
        }

        $this->panels[$id] = $panel;
    }

    public function has(string $id): bool
    {
        return isset($this->panels[$id]);
    }

    public function get(string $id): Panel
    {
        return $this->panels[$id] ?? throw PanelRegistrationException::unknownPanel($id);
    }

    /**
     * Sorted by id so route registration order is stable across runs.
     *
     * @return list<Panel>
     */
    public function all(): array
    {
        $panels = $this->panels;
        ksort($panels);

        return array_values($panels);
    }

    public function count(): int
    {
        return count($this->panels);
    }
}
