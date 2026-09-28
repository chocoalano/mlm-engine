<?php

declare(strict_types=1);

namespace PandaPanel\Search;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Resources\Resource as PanelResource;

/**
 * Searches every resource in a panel that opted in.
 *
 * Three rules hold everywhere here:
 *
 * - A resource that did not opt in is not searched, so adding one to a panel
 *   never silently widens what a search can reach.
 * - Every resource is authorized with `canViewAny()` before it is queried,
 *   and each result's URL is a page the same policy already allows.
 * - Searching starts from `Resource::query()`, so a tenant or permission
 *   scope narrows the search exactly as it narrows a list.
 */
final class GlobalSearch
{
    public function __construct(private readonly PanelManager $manager) {}

    /**
     * @return list<array{resource: string, label: string, icon: string|null, results: list<array<string, mixed>>}>
     */
    public function for(Panel $panel, string $term): array
    {
        $term = trim($term);

        if (! $panel->hasGlobalSearch() || mb_strlen($term) < 2) {
            return [];
        }

        $groups = [];
        $remaining = $panel->getGlobalSearchLimit();

        foreach ($this->searchableResources($panel) as $resource) {
            if ($remaining <= 0) {
                break;
            }

            $results = $this->search($resource, $term, min($resource::globalSearchLimit(), $remaining));

            if ($results === []) {
                continue;
            }

            $remaining -= count($results);

            $groups[] = [
                'resource' => $resource::slug(),
                'label' => $resource::pluralLabel(),
                'icon' => $resource::navigationIcon(),
                'results' => array_map(
                    static fn (GlobalSearchResult $result): array => $result->toArray(),
                    $results,
                ),
            ];
        }

        return $groups;
    }

    /**
     * Resources that opted in and that this user may view, in the order the
     * panel should present them.
     *
     * @return list<class-string<PanelResource>>
     */
    private function searchableResources(Panel $panel): array
    {
        $resources = [];

        foreach ($this->manager->resources($panel)->all() as $resource) {
            if (! is_subclass_of($resource, PanelResource::class)) {
                continue;
            }

            if (! $resource::isGloballySearchable() || ! $resource::canViewAny()) {
                continue;
            }

            $resources[] = $resource;
        }

        usort(
            $resources,
            static fn (string $a, string $b): int => [$a::globalSearchSort(), $a::slug()]
                <=> [$b::globalSearchSort(), $b::slug()],
        );

        return $resources;
    }

    /**
     * @param  class-string<PanelResource>  $resource
     * @return list<GlobalSearchResult>
     */
    private function search(string $resource, string $term, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $query = $this->constrain($resource::globalSearchQuery(), $resource::globalSearchAttributes(), $term);

        $results = [];

        foreach ($query->limit($limit)->get() as $record) {
            $results[] = new GlobalSearchResult(
                title: $resource::globalSearchResultTitle($record),
                url: $resource::globalSearchResultUrl($record),
                details: $resource::globalSearchResultDetails($record),
            );
        }

        return $results;
    }

    /**
     * Attributes are a whitelist, and a dotted one searches the relation it
     * names. Nothing from the request ever reaches a column name.
     *
     * @param  Builder<covariant Model>  $query
     * @param  list<string>  $attributes
     * @return Builder<covariant Model>
     */
    private function constrain(Builder $query, array $attributes, string $term): Builder
    {
        $like = '%'.$this->escapeLike($term).'%';

        return $query->where(static function (Builder $query) use ($attributes, $like): void {
            foreach ($attributes as $attribute) {
                if (! str_contains($attribute, '.')) {
                    $query->orWhere($attribute, 'like', $like);

                    continue;
                }

                [$relation, $column] = explode('.', $attribute, 2);

                $query->orWhereHas(
                    $relation,
                    static fn (Builder $related): Builder => $related->where($column, 'like', $like),
                );
            }
        });
    }

    /**
     * Escapes SQL LIKE wildcards so a search term containing `%` or `_`
     * searches for those characters instead of widening the query.
     */
    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }
}
