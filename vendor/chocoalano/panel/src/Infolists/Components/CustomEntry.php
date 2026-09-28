<?php

declare(strict_types=1);

namespace PandaPanel\Infolists\Components;

use Closure;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Infolists\Enums\EntryType;

/**
 * An entry drawn by a component of this application's own.
 *
 * The component is a build-time registry key under
 * `resources/js/pages/Panels/{Panel}/Entries/`, never markup and never a path
 * — the same rule custom columns, fields, and widgets follow.
 *
 * Its value is whatever the entry resolves, so a custom renderer is a
 * different way of *drawing* a value rather than a way of fetching one.
 */
final class CustomEntry extends Entry
{
    private string $component = '';

    /** @var array<string, mixed> */
    private array $config = [];

    /** @var (Closure(Model): mixed)|null */
    private ?Closure $stateUsing = null;

    public function type(): EntryType
    {
        return EntryType::Custom;
    }

    public function component(string $component): self
    {
        $this->component = $component;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function config(array $config): self
    {
        $this->config = $config;

        return $this;
    }

    /**
     * Builds the value from the whole record rather than from one attribute,
     * for a renderer that needs more than a column holds.
     *
     * @param  Closure(Model): mixed  $callback
     */
    public function state(Closure $callback): self
    {
        $this->stateUsing = $callback;

        return $this;
    }

    public function toValue(Model $record): mixed
    {
        return $this->stateUsing === null
            ? $this->resolveValue($record)
            : ($this->stateUsing)($record);
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return [
            'componentName' => $this->component,
            'config' => $this->config,
        ];
    }
}
