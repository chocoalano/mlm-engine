<?php

declare(strict_types=1);

namespace PandaPanel\Support;

/**
 * An ordered set of navigation items rendered under one sidebar heading.
 *
 * A null label means the items render without a heading, which is how the
 * dashboard and other ungrouped entries appear.
 */
final readonly class NavigationGroup
{
    /**
     * @param  list<NavigationItem>  $items
     */
    /**
     * @param  list<NavigationItem>  $items
     * @param  string|null  $parent  the group this one nests under, if any
     */
    public function __construct(
        public ?string $label,
        public int $sort,
        public bool $collapsible,
        public array $items,
        public ?string $parent = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * @return array{label: string|null, sort: int, collapsible: bool, parent: string|null, items: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'sort' => $this->sort,
            'collapsible' => $this->collapsible,
            // The sidebar draws a nested group indented under its parent
            // rather than as a second heading at the top level.
            'parent' => $this->parent,
            'items' => array_map(
                static fn (NavigationItem $item): array => $item->toArray(),
                $this->items,
            ),
        ];
    }
}
