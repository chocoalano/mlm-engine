<?php

declare(strict_types=1);

namespace PandaPanel\Tables;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Support\Label;

/**
 * One filter tab above a list page.
 *
 * A tab is a named scope on the resource's own query, never a query of its
 * own: it receives `Resource::query()` and returns it narrowed, so a tenant
 * or permission scope still applies to whatever the tab shows.
 *
 * The badge may be a closure. It is evaluated on the server and only the
 * scalar crosses to Vue, like a navigation badge.
 */
final class Tab
{
    /** @var (Closure(Builder<covariant Model>): Builder<covariant Model>)|null */
    private ?Closure $modifyQueryUsing = null;

    /** @var string|int|(Closure(): (string|int|null))|null */
    private string|int|Closure|null $badge = null;

    private ?string $icon = null;

    private function __construct(
        public readonly string $key,
        private ?string $label = null,
    ) {}

    public static function make(string $key, ?string $label = null): self
    {
        return new self($key, $label);
    }

    /**
     * @param  Closure(Builder<covariant Model>): Builder<covariant Model>  $callback
     */
    public function query(Closure $callback): self
    {
        $this->modifyQueryUsing = $callback;

        return $this;
    }

    /**
     * @param  string|int|(Closure(): (string|int|null))|null  $badge
     */
    public function badge(string|int|Closure|null $badge): self
    {
        $this->badge = $badge;

        return $this;
    }

    /**
     * An icon registry key, never a component path.
     */
    public function icon(?string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label ?? Label::resolve(
            'tabs',
            $this->key,
            fn (): string => str($this->key)->headline()->toString(),
        );
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @return Builder<covariant Model>
     */
    public function apply(Builder $query): Builder
    {
        return $this->modifyQueryUsing === null
            ? $query
            : ($this->modifyQueryUsing)($query);
    }

    public function resolveBadge(): string|int|null
    {
        return $this->badge instanceof Closure ? ($this->badge)() : $this->badge;
    }

    /**
     * @return array{key: string, label: string, icon: string|null, badge: string|int|null, active: bool}
     */
    public function toArray(bool $active): array
    {
        return [
            'key' => $this->key,
            'label' => $this->getLabel(),
            'icon' => $this->icon,
            'badge' => $this->resolveBadge(),
            'active' => $active,
        ];
    }
}
