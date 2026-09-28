<?php

declare(strict_types=1);

namespace PandaPanel\Support;

use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Resources\Resource as PanelResource;

/**
 * Turns a panel's registered resources and pages into sidebar groups.
 *
 * Everything here is per-request on purpose. Authorization results, badge
 * values, and active state all depend on the current user and URL, so none
 * of it may be cached alongside the static panel manifest.
 *
 * Authorization is evaluated for the currently authenticated user, because
 * the resource and page contracts expose static `canViewAny()` / `canAccess()`
 * that consult the Gate themselves. There is deliberately no user parameter:
 * accepting one the builder cannot honour would suggest it can render a
 * sidebar as somebody else, which it cannot.
 *
 * Navigation visibility is a convenience layer. Routes and actions authorize
 * independently; hiding an item is never the security control.
 */
final class NavigationBuilder
{
    public function __construct(private readonly PanelManager $manager) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function for(Panel $panel, string $currentPath): array
    {
        return array_map(
            static fn (NavigationGroup $group): array => $group->toArray(),
            $this->groupsFor($panel, $currentPath),
        );
    }

    /**
     * @return list<NavigationGroup>
     */
    public function groupsFor(Panel $panel, string $currentPath): array
    {
        $items = $this->visibleItems($panel);
        $items = $this->applyActiveState($items, $currentPath);
        $items = $this->applyFullPage($panel, $items);

        return $this->group($panel, $items);
    }

    /**
     * Marks the items the panel declared as full-page destinations, children
     * included: a link's own href decides, not its parent's.
     *
     * @param  list<NavigationItem>  $items
     * @return list<NavigationItem>
     */
    private function applyFullPage(Panel $panel, array $items): array
    {
        if ($panel->getFullPageUrls() === []) {
            return $items;
        }

        return array_map(
            fn (NavigationItem $item): NavigationItem => $item
                ->withChildren($this->applyFullPage($panel, $item->children))
                ->withFullPage($panel->isFullPageUrl($item->href)),
            $items,
        );
    }

    /**
     * Resources and pages the current user may see.
     *
     * `canViewAny()` / `canAccess()` run before anything else so an
     * unauthorized item never reaches badge evaluation.
     *
     * @return list<NavigationItem>
     */
    private function visibleItems(Panel $panel): array
    {
        $clustered = ClusterNavigation::all($panel);
        $items = [];

        // A cluster is one item that expands to its members, so the members
        // themselves are not listed beside it. Built first, because what
        // follows has to know which classes were already accounted for.
        foreach ($clustered as $cluster => $children) {
            if (! $cluster::canAccess() || ! $cluster::shouldRegisterNavigation()) {
                continue;
            }

            $items[] = $cluster::navigationItem($panel) ?? NavigationItem::make(
                label: $cluster::title(),
                // The first member the user may see: a cluster is a
                // container, and only this knows which of its members are
                // visible to whoever is asking.
                href: $children[0]->href,
                icon: $cluster::navigationIcon(),
                sort: $cluster::navigationSort(),
                group: $cluster::navigationGroup(),
                children: $children,
                activeIcon: $cluster::activeNavigationIcon(),
            );
        }

        foreach ($this->manager->resources($panel)->all() as $resource) {
            // Already listed under its cluster.
            if ($resource::cluster() !== null) {
                continue;
            }

            if (! $resource::canViewAny()) {
                // The one case worth explaining: denied because nobody wrote
                // a policy, rather than because one said no. Development
                // only, once per model — see `MissingPolicyNotice`.
                //
                // The registry is typed to the contract, which does not
                // declare a model; the base class is what a panel registers.
                if (is_subclass_of($resource, PanelResource::class)) {
                    MissingPolicyNotice::reportIfMissing($resource, $resource::getModel());
                }

                continue;
            }

            $item = $resource::navigationItem($panel);

            if ($item instanceof NavigationItem) {
                $items[] = $item;
            }
        }

        // A panel's extra dashboards are named on the panel rather than
        // discovered, so they are listed here beside the pages that were —
        // deduplicated, because one that also lives under a discovered path
        // arrives twice and is still one item.
        $pages = array_values(array_unique([
            ...$panel->getExtraDashboards(),
            ...$this->manager->pages($panel)->all(),
        ]));

        foreach ($pages as $page) {
            if ($page::cluster() !== null) {
                continue;
            }

            if (! $page::canAccess()) {
                continue;
            }

            $item = $page::navigationItem($panel);

            if ($item instanceof NavigationItem) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Exactly one item is active. An exact path match always wins; otherwise
     * the longest matching prefix wins, so `/admin/users/3/edit` keeps the
     * Users item active without also lighting up `/admin`.
     *
     * @param  list<NavigationItem>  $items
     * @return list<NavigationItem>
     */
    private function applyActiveState(array $items, string $currentPath): array
    {
        $current = '/'.trim($currentPath, '/');
        $bestIndex = null;
        $bestLength = -1;

        foreach ($items as $index => $item) {
            $href = $this->normalizeHref($item->href);

            if ($href === null) {
                continue;
            }

            if ($href === $current) {
                $bestIndex = $index;
                break;
            }

            if (str_starts_with($current, rtrim($href, '/').'/') && strlen($href) > $bestLength) {
                $bestIndex = $index;
                $bestLength = strlen($href);
            }
        }

        if ($bestIndex === null) {
            return $items;
        }

        $items[$bestIndex] = $items[$bestIndex]->withActive(true);

        return array_values($items);
    }

    /**
     * Only same-origin paths take part in active matching. An item pointing
     * at an absolute external URL is never marked active.
     */
    private function normalizeHref(string $href): ?string
    {
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            $path = parse_url($href, PHP_URL_PATH);

            if (! is_string($path)) {
                return null;
            }

            $href = $path;
        }

        if (! str_starts_with($href, '/')) {
            return null;
        }

        $href = rtrim($href, '/');

        return $href === '' ? '/' : $href;
    }

    /**
     * @param  list<NavigationItem>  $items
     * @return list<NavigationGroup>
     */
    private function group(Panel $panel, array $items): array
    {
        $registry = $this->manager->navigation($panel);

        $undeclared = [];

        foreach ($items as $item) {
            if ($item->group !== null && ! $registry->isDeclared($item->group)) {
                $undeclared[] = $item->group;
            }
        }

        /** @var array<string, list<NavigationItem>> $buckets */
        $buckets = [];

        foreach ($items as $item) {
            $buckets[$item->group ?? ''][] = $item;
        }

        $groups = [];

        foreach ($buckets as $key => $bucket) {
            $label = $key === '' ? null : $key;

            usort(
                $bucket,
                static fn (NavigationItem $a, NavigationItem $b): int => [$a->sort, $a->label] <=> [$b->sort, $b->label],
            );

            $group = new NavigationGroup(
                label: $label,
                sort: $registry->sortFor($label, $undeclared),
                collapsible: $registry->isCollapsible($label),
                items: $bucket,
                parent: $label === null
                    ? null
                    : ($panel->getNavigationGroupParents()[$label] ?? null),
            );

            if (! $group->isEmpty()) {
                $groups[] = $group;
            }
        }

        usort(
            $groups,
            static fn (NavigationGroup $a, NavigationGroup $b): int => [$a->sort, $a->label ?? ''] <=> [$b->sort, $b->label ?? ''],
        );

        return $groups;
    }
}
