<?php

declare(strict_types=1);

namespace PandaPanel\Core;

use PandaPanel\Contracts\ResourceContract;
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Resources\Resource;
use PandaPanel\Resources\ResourceConfiguration;

/**
 * Panel-scoped resource lookup, keyed by slug.
 *
 * The registry, not the class, owns the effective slug: the same class may
 * sit in two panels under different slugs, and only the panel knows which.
 * Everything that builds a route or a URL asks here.
 *
 * Ordering is by class name so registration is deterministic across runs and
 * across filesystem orderings. Sidebar ordering is a separate concern owned
 * by the navigation builder, which sorts by navigation sort.
 */
final class ResourceRegistry
{
    /** @var array<string, class-string<ResourceContract>> */
    private array $resources = [];

    /** @var array<class-string, ResourceConfiguration> */
    private array $configurations = [];

    /**
     * @param  class-string<ResourceContract>|ResourceConfiguration  $resource
     */
    public function register(string|ResourceConfiguration $resource): void
    {
        $configuration = $resource instanceof ResourceConfiguration
            ? $resource
            : (is_subclass_of($resource, Resource::class) ? ResourceConfiguration::for($resource) : null);

        $class = $resource instanceof ResourceConfiguration ? $resource->resource : $resource;
        $slug = $configuration?->getSlug() ?? $class::slug();

        if (isset($this->resources[$slug]) && $this->resources[$slug] !== $class) {
            throw PanelRegistrationException::duplicateResourceSlug($slug, $this->resources[$slug], $class);
        }

        // One class, one registration per panel: two would make a URL for it
        // ambiguous, with no way to say which of the two was meant.
        if (isset($this->configurations[$class]) && $this->configurations[$class]->getSlug() !== $slug) {
            throw PanelRegistrationException::duplicateResourceSlug(
                $this->configurations[$class]->getSlug(),
                $class,
                $class,
            );
        }

        $this->resources[$slug] = $class;

        if ($configuration !== null) {
            $this->configurations[$class] = $configuration;
        }
    }

    /**
     * The configuration a class was registered with in this panel, if it is
     * registered here at all.
     *
     * @param  class-string  $resource
     */
    public function configurationFor(string $resource): ?ResourceConfiguration
    {
        return $this->configurations[$resource] ?? null;
    }

    /**
     * The slug a class was registered under here.
     *
     * @param  class-string  $resource
     */
    public function slugFor(string $resource): string
    {
        $slug = array_search($resource, $this->resources, true);

        return $slug === false
            ? (is_subclass_of($resource, Resource::class) ? $resource::defaultSlug() : '')
            : $slug;
    }

    public function has(string $slug): bool
    {
        return isset($this->resources[$slug]);
    }

    /**
     * @return class-string<ResourceContract>|null
     */
    public function bySlug(string $slug): ?string
    {
        return $this->resources[$slug] ?? null;
    }

    /**
     * @param  class-string<ResourceContract>  $resource
     */
    public function contains(string $resource): bool
    {
        return in_array($resource, $this->resources, true);
    }

    /**
     * @return list<class-string<ResourceContract>>
     */
    public function all(): array
    {
        $resources = array_values($this->resources);
        sort($resources);

        return $resources;
    }

    /**
     * @return list<string>
     */
    public function slugs(): array
    {
        return array_keys($this->resources);
    }

    public function count(): int
    {
        return count($this->resources);
    }
}
