<?php

declare(strict_types=1);

namespace PandaPanel\Clusters;

use BackedEnum;
use Illuminate\Support\Str;
use PandaPanel\Core\Panel;
use PandaPanel\Enums\ClusterPosition;
use PandaPanel\Support\Label;
use PandaPanel\Support\NavigationItem;

/**
 * A set of resources and pages that belong together.
 *
 * Two things at once, deliberately: a URL prefix and a piece of navigation.
 * Everything in a cluster lives under its slug — `/admin/settings/roles` —
 * and every page in it renders the cluster's own sub-navigation, so moving
 * between siblings never means going back to the sidebar.
 *
 * Route *names* are untouched by the prefix. A resource is still
 * `panel.admin.resources.roles.index`, so every `Resource::url()` in the
 * application keeps working and only the path it produces changes.
 *
 * Membership is declared by the member, not by the cluster: a resource says
 * `protected static ?string $cluster = SettingsCluster::class;`. That way a
 * class carries its own place in the panel and nothing has to be kept in two
 * lists that can disagree.
 */
abstract class Cluster
{
    protected static ?string $title = null;

    protected static ?string $slug = null;

    protected static ?string $navigationIcon = null;

    protected static ?string $activeNavigationIcon = null;

    protected static string|BackedEnum|null $navigationGroup = null;

    protected static int $navigationSort = 0;

    protected static bool $shouldRegisterNavigation = true;

    protected static ClusterPosition $position = ClusterPosition::Header;

    public static function title(): string
    {
        $name = Str::beforeLast(class_basename(static::class), 'Cluster');

        return static::$title ?? Label::resolve(
            'clusters',
            $name,
            static fn (): string => Str::headline($name),
        );
    }

    public static function slug(): string
    {
        return static::$slug ?? Str::kebab(
            Str::beforeLast(class_basename(static::class), 'Cluster'),
        );
    }

    public static function navigationIcon(): ?string
    {
        return static::$navigationIcon;
    }

    public static function activeNavigationIcon(): ?string
    {
        return static::$activeNavigationIcon ?? static::$navigationIcon;
    }

    public static function navigationGroup(): string|BackedEnum|null
    {
        return static::$navigationGroup;
    }

    public static function navigationSort(): int
    {
        return static::$navigationSort;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::$shouldRegisterNavigation;
    }

    public static function position(): ClusterPosition
    {
        return static::$position;
    }

    /**
     * Whether this cluster is open to the current user at all.
     *
     * Independent of its members: a cluster the user may not enter hides the
     * whole set, and every member still authorizes for itself. Hiding is
     * never the control.
     */
    public static function canAccess(): bool
    {
        return true;
    }

    /**
     * The cluster's own landing item, when it has one.
     *
     * Null by default: a cluster is a container, and its first member is
     * where clicking it should go — which the navigation builder works out,
     * because only it knows which members the user may see.
     */
    public static function navigationItem(Panel $panel): ?NavigationItem
    {
        return null;
    }
}
