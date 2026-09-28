<?php

declare(strict_types=1);

namespace PandaPanel\Support;

use BackedEnum;
use Closure;

/**
 * A single sidebar entry.
 *
 * Immutable: the `with*` methods return a new instance. Badges may be given
 * as a closure, which the navigation builder evaluates backend-side so only
 * the scalar result is ever serialized.
 */
final readonly class NavigationItem
{
    /**
     * @param  string|int|Closure(): (string|int|null)|null  $badge
     * @param  list<NavigationItem>  $children
     * @param  string|null  $activeIcon  a second icon worn only while active
     */
    public function __construct(
        public string $label,
        public string $href,
        public ?string $icon = null,
        public string|int|Closure|null $badge = null,
        public bool $active = false,
        public int $sort = 0,
        public ?string $group = null,
        public array $children = [],
        public bool $fullPage = false,
        public ?string $activeIcon = null,
    ) {}

    /**
     * A group may be named by a string or by a backed enum.
     *
     * An enum is worth reaching for once more than one class names the same
     * group: a typo in a string is a second group that looks like the first,
     * and a typo in an enum case does not compile.
     *
     * @param  string|int|Closure(): (string|int|null)|null  $badge
     * @param  list<NavigationItem>  $children
     */
    public static function make(
        string $label,
        string $href,
        ?string $icon = null,
        string|int|Closure|null $badge = null,
        int $sort = 0,
        string|BackedEnum|null $group = null,
        array $children = [],
        ?string $activeIcon = null,
    ): self {
        return new self(
            label: $label,
            href: $href,
            icon: $icon,
            badge: $badge,
            sort: $sort,
            group: NavigationGroupName::resolve($group),
            children: $children,
            activeIcon: $activeIcon,
        );
    }

    public function withActive(bool $active): self
    {
        return $this->with(active: $active);
    }

    /**
     * @param  list<NavigationItem>  $children
     */
    public function withChildren(array $children): self
    {
        return $this->with(children: $children);
    }

    public function withFullPage(bool $fullPage): self
    {
        return $this->with(fullPage: $fullPage);
    }

    /**
     * Resolve a closure badge to its scalar value. Called only for items that
     * survived the authorization filter.
     */
    public function resolveBadge(): string|int|null
    {
        return $this->badge instanceof Closure
            ? ($this->badge)()
            : $this->badge;
    }

    /**
     * @return array{label: string, href: string, icon: string|null, activeIcon: string|null, badge: string|int|null, active: bool, sort: int, fullPage: bool, children: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'href' => $this->href,
            'icon' => $this->icon,
            // Sent whether or not the item is active, so the sidebar can swap
            // icons on a client-side navigation without waiting for the
            // server to say which item won.
            'activeIcon' => $this->activeIcon ?? $this->icon,
            'badge' => $this->resolveBadge(),
            'active' => $this->active,
            'sort' => $this->sort,
            'fullPage' => $this->fullPage,
            'children' => array_map(
                static fn (NavigationItem $child): array => $child->toArray(),
                $this->children,
            ),
        ];
    }

    /**
     * One place the copy is made, so adding a property does not mean editing
     * three constructors' worth of argument lists.
     *
     * @param  list<NavigationItem>|null  $children
     */
    private function with(
        ?bool $active = null,
        ?array $children = null,
        ?bool $fullPage = null,
    ): self {
        return new self(
            label: $this->label,
            href: $this->href,
            icon: $this->icon,
            badge: $this->badge,
            active: $active ?? $this->active,
            sort: $this->sort,
            group: $this->group,
            children: $children ?? $this->children,
            fullPage: $fullPage ?? $this->fullPage,
            activeIcon: $this->activeIcon,
        );
    }
}
