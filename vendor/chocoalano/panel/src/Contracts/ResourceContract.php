<?php

declare(strict_types=1);

namespace PandaPanel\Contracts;

use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Clusters\Cluster;
use PandaPanel\Support\NavigationItem;

/**
 * Implemented by `PandaPanel\Resources\Resource`.
 *
 * Registries and the navigation builder type-hint this contract rather than
 * the base class so a future module can supply its own implementation.
 */
interface ResourceContract
{
    public static function slug(): string;

    /**
     * The single query every list, view, edit, delete, and action lookup
     * must resolve through.
     *
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    public static function query(): Builder;

    /**
     * Page key to page class, for example `['index' => ListUsers::class]`.
     *
     * @return array<string, class-string>
     */
    public static function pages(): array;

    public static function canViewAny(): bool;

    /**
     * Null when the resource opts out of navigation.
     */
    public static function navigationItem(PanelContract $panel): ?NavigationItem;

    /**
     * The cluster this resource belongs to, or null when it stands alone.
     *
     * On the contract because the navigation builder asks every registered
     * class the question before it can decide what to list: a clustered
     * resource is listed under its cluster and not beside it.
     *
     * @return class-string<Cluster>|null
     */
    public static function cluster(): ?string;
}
