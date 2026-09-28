<?php

declare(strict_types=1);

namespace PandaPanel\Support;

use PandaPanel\Clusters\Cluster;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Pages\Page;
use PandaPanel\Resources\Resource as PanelResource;

/**
 * The sub-navigation a page inside a cluster renders.
 *
 * Built from the cluster's members — every resource and page that named it —
 * so a cluster never keeps a second list that can disagree with what actually
 * belongs to it.
 *
 * Authorization is the members' own: a resource the user may not view is not
 * in the list, and a cluster whose every member is refused produces nothing
 * rather than an empty bar.
 */
final class ClusterNavigation
{
    /**
     * Every cluster with at least one visible member, in navigation order.
     *
     * @return array<class-string<Cluster>, list<NavigationItem>>
     */
    public static function all(Panel $panel): array
    {
        $manager = app(PanelManager::class);
        $clusters = [];

        foreach ($manager->resources($panel)->all() as $resource) {
            /** @var class-string<PanelResource> $resource */
            $cluster = $resource::cluster();

            if ($cluster === null || ! $resource::canViewAny()) {
                continue;
            }

            $item = $resource::navigationItem($panel);

            if ($item instanceof NavigationItem) {
                $clusters[$cluster][] = $item;
            }
        }

        foreach ($manager->pages($panel)->all() as $page) {
            /** @var class-string<Page> $page */
            $cluster = $page::cluster();

            if ($cluster === null || ! $page::canAccess()) {
                continue;
            }

            $item = $page::navigationItem($panel);

            if ($item instanceof NavigationItem) {
                $clusters[$cluster][] = $item;
            }
        }

        foreach ($clusters as $cluster => $items) {
            usort(
                $items,
                static fn (NavigationItem $a, NavigationItem $b): int => [$a->sort, $a->label] <=> [$b->sort, $b->label],
            );

            $clusters[$cluster] = $items;
        }

        return $clusters;
    }

    /**
     * One cluster's sub-navigation, ready to send with a page.
     *
     * Null when the cluster has nothing this user may see, so a page never
     * renders a bar with no links in it.
     *
     * @param  class-string<Cluster>  $cluster
     * @return array{label: string, icon: string|null, position: string, items: list<array<string, mixed>>}|null
     */
    public static function for(Panel $panel, string $cluster, string $currentPath): ?array
    {
        if (! $cluster::canAccess()) {
            return null;
        }

        $items = self::all($panel)[$cluster] ?? [];

        if ($items === []) {
            return null;
        }

        return [
            'label' => $cluster::title(),
            'icon' => $cluster::navigationIcon(),
            'position' => $cluster::position()->value,
            'items' => array_map(
                static fn (NavigationItem $item): array => $item
                    ->withActive(self::isCurrent($item, $currentPath))
                    ->toArray(),
                $items,
            ),
        ];
    }

    /**
     * Whether this item is the one being looked at.
     *
     * A prefix match, because `/admin/settings/roles/3/edit` is still the
     * roles item — the same rule the sidebar's own active state follows.
     */
    private static function isCurrent(NavigationItem $item, string $currentPath): bool
    {
        $current = '/'.trim($currentPath, '/');
        $href = rtrim($item->href, '/');

        return $href !== '' && ($current === $href || str_starts_with($current, $href.'/'));
    }
}
