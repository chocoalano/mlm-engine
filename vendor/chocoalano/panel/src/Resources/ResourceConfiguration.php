<?php

declare(strict_types=1);

namespace PandaPanel\Resources;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Resources\Resource as PanelResource;

/**
 * One resource class, configured for one panel.
 *
 * The same class can appear in several panels and mean something slightly
 * different in each: a different slug, a different place in the sidebar, a
 * narrower query. Without this, a class shared by two panels would have to
 * agree with itself everywhere, and the only way out would be a subclass
 * that exists purely to change a label.
 *
 * Deliberately *not* a way to register one class twice inside a single
 * panel. A panel keys its resources by slug, and two registrations of one
 * class would make `Resource::url()` ambiguous — there would be no way to
 * ask which of the two a link meant.
 */
final class ResourceConfiguration
{
    private ?string $slug = null;

    private ?string $label = null;

    private ?string $pluralLabel = null;

    private ?string $navigationLabel = null;

    private ?string $navigationGroup = null;

    private ?string $navigationIcon = null;

    private ?int $navigationSort = null;

    private ?bool $shouldRegisterNavigation = null;

    /** @var (Closure(Builder<covariant Model>): Builder<covariant Model>)|null */
    private ?Closure $modifyQueryUsing = null;

    /**
     * @param  class-string<PanelResource>  $resource
     */
    private function __construct(public readonly string $resource) {}

    /**
     * @param  class-string<PanelResource>  $resource
     */
    public static function for(string $resource): self
    {
        return new self($resource);
    }

    public function slug(string $slug): self
    {
        $this->slug = $slug;

        return $this;
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function pluralLabel(string $pluralLabel): self
    {
        $this->pluralLabel = $pluralLabel;

        return $this;
    }

    public function navigationLabel(string $navigationLabel): self
    {
        $this->navigationLabel = $navigationLabel;

        return $this;
    }

    public function navigationGroup(?string $navigationGroup): self
    {
        $this->navigationGroup = $navigationGroup;

        return $this;
    }

    public function navigationIcon(?string $navigationIcon): self
    {
        $this->navigationIcon = $navigationIcon;

        return $this;
    }

    public function navigationSort(int $navigationSort): self
    {
        $this->navigationSort = $navigationSort;

        return $this;
    }

    public function registerNavigation(bool $register = true): self
    {
        $this->shouldRegisterNavigation = $register;

        return $this;
    }

    /**
     * Narrows what this panel sees, on top of the resource's own `query()`.
     *
     * Applied everywhere the resource is read — list, view, edit, delete,
     * bulk, action lookup, and global search — because they all go through
     * `query()`. A record this panel may not reach is a 404 here, not a
     * filtered row.
     *
     * @param  Closure(Builder<covariant Model>): Builder<covariant Model>  $callback
     */
    public function modifyQueryUsing(Closure $callback): self
    {
        $this->modifyQueryUsing = $callback;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug ?? $this->resource::defaultSlug();
    }

    public function getLabel(): string
    {
        return $this->label ?? $this->resource::defaultLabel();
    }

    public function getPluralLabel(): string
    {
        return $this->pluralLabel ?? $this->resource::defaultPluralLabel();
    }

    public function getNavigationLabel(): ?string
    {
        return $this->navigationLabel;
    }

    public function getNavigationGroup(): ?string
    {
        return $this->navigationGroup;
    }

    public function getNavigationIcon(): ?string
    {
        return $this->navigationIcon;
    }

    public function getNavigationSort(): ?int
    {
        return $this->navigationSort;
    }

    public function shouldRegisterNavigation(): ?bool
    {
        return $this->shouldRegisterNavigation;
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @return Builder<covariant Model>
     */
    public function applyQuery(Builder $query): Builder
    {
        return $this->modifyQueryUsing === null ? $query : ($this->modifyQueryUsing)($query);
    }
}
