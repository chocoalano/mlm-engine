<?php

declare(strict_types=1);

namespace PandaPanel\Core;

use PandaPanel\Contracts\PageContract;
use PandaPanel\Exceptions\PanelRegistrationException;

/**
 * Panel-scoped standalone page lookup, keyed by slug.
 *
 * Page slugs are validated against the resource registry as well, so a page
 * can never shadow a resource route inside the same panel.
 */
final class PageRegistry
{
    /** @var array<string, class-string<PageContract>> */
    private array $pages = [];

    public function __construct(private readonly ResourceRegistry $resources) {}

    /**
     * @param  class-string<PageContract>  $page
     */
    public function register(string $page): void
    {
        $slug = $page::slug();

        if (isset($this->pages[$slug]) && $this->pages[$slug] !== $page) {
            throw PanelRegistrationException::duplicatePageSlug($slug, $this->pages[$slug], $page);
        }

        $resource = $this->resources->bySlug($slug);

        if ($resource !== null) {
            throw PanelRegistrationException::slugCollidesWithResource($slug, $page, $resource);
        }

        $this->pages[$slug] = $page;
    }

    public function has(string $slug): bool
    {
        return isset($this->pages[$slug]);
    }

    /**
     * @return class-string<PageContract>|null
     */
    public function bySlug(string $slug): ?string
    {
        return $this->pages[$slug] ?? null;
    }

    /**
     * @return list<class-string<PageContract>>
     */
    public function all(): array
    {
        $pages = array_values($this->pages);
        sort($pages);

        return $pages;
    }

    public function count(): int
    {
        return count($this->pages);
    }
}
