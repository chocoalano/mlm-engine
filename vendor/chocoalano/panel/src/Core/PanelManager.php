<?php

declare(strict_types=1);

namespace PandaPanel\Core;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use PandaPanel\Cache\PanelManifest;
use PandaPanel\Contracts\PageContract;
use PandaPanel\Contracts\ResourceContract;
use PandaPanel\Contracts\WidgetContract;
use PandaPanel\Support\PanelContext;

/**
 * The entry point for everything panel-related.
 *
 * Registration happens once during provider boot. Current-panel state is
 * delegated to the request-scoped PanelContext, never held statically here,
 * so nothing leaks between requests or between tests.
 *
 * Class discovery is not this class's job. It receives explicit class lists
 * now and will receive discovered or cached ones later without a shape
 * change.
 */
final class PanelManager
{
    /** @var array<string, ResourceRegistry> */
    private array $resourceRegistries = [];

    /** @var array<string, PageRegistry> */
    private array $pageRegistries = [];

    /** @var array<string, WidgetRegistry> */
    private array $widgetRegistries = [];

    /** @var array<string, NavigationRegistry> */
    private array $navigationRegistries = [];

    public function __construct(
        private readonly PanelRegistry $panels,
        private readonly PanelContext $context,
        private readonly PanelManifest $manifest,
    ) {}

    /**
     * @param  class-string<PanelProvider>  $provider
     */
    public function registerProvider(string $provider): Panel
    {
        return $this->register((new $provider)->build());
    }

    public function register(Panel $panel): Panel
    {
        $this->panels->register($panel);
        $this->buildRegistries($panel);

        return $panel;
    }

    /**
     * @return list<Panel>
     */
    public function all(): array
    {
        return $this->panels->all();
    }

    public function has(string $id): bool
    {
        return $this->panels->has($id);
    }

    public function get(string $id): Panel
    {
        return $this->panels->get($id);
    }

    /**
     * Match a panel by request domain and path prefix.
     *
     * Longest path first, so a panel at `/admin/reports` wins over one at
     * `/admin` for a request to `/admin/reports/x`.
     */
    public function resolveFromRequest(Request $request): ?Panel
    {
        $path = trim($request->path(), '/');
        $host = $request->getHost();

        $candidates = $this->all();

        usort(
            $candidates,
            static fn (Panel $a, Panel $b): int => strlen($b->getPath()) <=> strlen($a->getPath()),
        );

        foreach ($candidates as $panel) {
            if ($panel->getDomain() !== null && $panel->getDomain() !== $host) {
                continue;
            }

            $prefix = $panel->getPath();

            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return $panel;
            }
        }

        return null;
    }

    /**
     * The panel to send a user to when the request itself does not name one.
     *
     * Registration order decides, so the answer is the same on every request
     * rather than depending on which route happened to run.
     */
    public function firstAccessibleTo(?Authenticatable $user): ?Panel
    {
        foreach ($this->all() as $panel) {
            if ($panel->isAccessibleTo($user)) {
                return $panel;
            }
        }

        return null;
    }

    public function setCurrentPanel(?Panel $panel): void
    {
        $this->context->setPanel($panel);
    }

    public function currentPanel(): ?Panel
    {
        return $this->context->panel();
    }

    public function hasCurrentPanel(): bool
    {
        return $this->context->hasPanel();
    }

    public function resources(Panel|string $panel): ResourceRegistry
    {
        return $this->resourceRegistries[$this->idFor($panel)] ??= new ResourceRegistry;
    }

    public function pages(Panel|string $panel): PageRegistry
    {
        return $this->pageRegistries[$this->idFor($panel)] ??= new PageRegistry($this->resources($panel));
    }

    public function widgets(Panel|string $panel): WidgetRegistry
    {
        return $this->widgetRegistries[$this->idFor($panel)] ??= new WidgetRegistry;
    }

    public function navigation(Panel|string $panel): NavigationRegistry
    {
        return $this->navigationRegistries[$this->idFor($panel)] ??= new NavigationRegistry;
    }

    /**
     * Populate the panel's registries.
     *
     * Explicitly registered classes are merged with whatever the manifest
     * supplies: the cached list when one exists, discovery otherwise. A
     * class named in both appears once, because the registries are keyed by
     * slug and id.
     */
    private function buildRegistries(Panel $panel): void
    {
        $id = $panel->getId();
        $discovered = $this->manifest->for($panel);

        $resources = new ResourceRegistry;

        // Configurations first: a class configured for this panel must not
        // also be registered bare, which would claim its default slug.
        foreach ($panel->getResourceConfigurations() as $configuration) {
            $resources->register($configuration);
        }

        foreach ([...$panel->getResources(), ...$discovered['resources']] as $resource) {
            /** @var class-string<ResourceContract> $resource */
            if ($resources->configurationFor($resource) !== null) {
                continue;
            }

            $resources->register($resource);
        }

        $pages = new PageRegistry($resources);

        foreach ([...$panel->getPages(), ...$discovered['pages']] as $page) {
            /** @var class-string<PageContract> $page */
            $pages->register($page);
        }

        $widgets = new WidgetRegistry;

        foreach ([...$panel->getWidgets(), ...$discovered['widgets']] as $widget) {
            /** @var class-string<WidgetContract> $widget */
            $widgets->register($widget);
        }

        $this->resourceRegistries[$id] = $resources;
        $this->pageRegistries[$id] = $pages;
        $this->widgetRegistries[$id] = $widgets;
        $this->navigationRegistries[$id] = (new NavigationRegistry($panel->getNavigationGroups()))
            ->collapsible($panel->getSidebar()['collapsible']);
    }

    private function idFor(Panel|string $panel): string
    {
        return $panel instanceof Panel ? $panel->getId() : $panel;
    }
}
