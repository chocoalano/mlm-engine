<?php

declare(strict_types=1);

namespace PandaPanel\Core;

/**
 * Sidebar group ordering for one panel.
 *
 * Groups declared on the panel keep their declared order. Groups that only
 * appear because a resource or page referenced them are appended
 * alphabetically, so an undeclared group never reshuffles the sidebar
 * depending on class discovery order.
 *
 * The ungrouped bucket (null label) always sorts first, which is where the
 * dashboard lives.
 */
final class NavigationRegistry
{
    private const UNGROUPED_SORT = -1;

    private const DECLARED_BASE = 0;

    private const UNDECLARED_BASE = 1000;

    /** @var array<string, int> */
    private array $declared = [];

    private bool $collapsible = true;

    /**
     * @param  list<string>  $groups
     */
    public function __construct(array $groups = [])
    {
        foreach ($groups as $index => $label) {
            $this->declared[$label] = self::DECLARED_BASE + $index;
        }
    }

    public function collapsible(bool $collapsible): self
    {
        $this->collapsible = $collapsible;

        return $this;
    }

    public function isCollapsible(?string $label): bool
    {
        return $label !== null && $this->collapsible;
    }

    /**
     * @return list<string>
     */
    public function declaredGroups(): array
    {
        return array_keys($this->declared);
    }

    public function isDeclared(string $label): bool
    {
        return isset($this->declared[$label]);
    }

    /**
     * Sort weight for a group label. Undeclared labels are ordered
     * alphabetically after every declared one.
     *
     * @param  list<string>  $undeclared  every undeclared label in play, unsorted
     */
    public function sortFor(?string $label, array $undeclared = []): int
    {
        if ($label === null) {
            return self::UNGROUPED_SORT;
        }

        if (isset($this->declared[$label])) {
            return $this->declared[$label];
        }

        $remaining = array_values(array_unique(array_filter(
            $undeclared,
            fn (string $candidate): bool => ! $this->isDeclared($candidate),
        )));

        sort($remaining);

        $position = array_search($label, $remaining, true);

        return self::UNDECLARED_BASE + ($position === false ? 0 : $position);
    }
}
