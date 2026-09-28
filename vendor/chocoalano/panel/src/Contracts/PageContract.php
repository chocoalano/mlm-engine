<?php

declare(strict_types=1);

namespace PandaPanel\Contracts;

use PandaPanel\Clusters\Cluster;
use PandaPanel\Support\NavigationItem;

/**
 * Implemented by `PandaPanel\Pages\Page`.
 */
interface PageContract
{
    public static function slug(): string;

    /**
     * The path relative to the panel prefix, without a leading slash.
     */
    public static function routePath(): string;

    /**
     * Enforced by the page route itself, never only by navigation visibility.
     */
    public static function canAccess(): bool;

    /**
     * Null when the page opts out of navigation.
     */
    public static function navigationItem(PanelContract $panel): ?NavigationItem;

    /**
     * The cluster this page belongs to, or null when it stands alone.
     *
     * On the contract because the navigation builder asks every registered
     * class the question before it can decide what to list: a clustered
     * page is listed under its cluster and not beside it.
     *
     * @return class-string<Cluster>|null
     */
    public static function cluster(): ?string;
}
