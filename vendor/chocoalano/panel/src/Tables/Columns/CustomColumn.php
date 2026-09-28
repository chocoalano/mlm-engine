<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Columns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Tables\Enums\ColumnType;

/**
 * A column drawn by a component of this application's own.
 *
 * The component is a build-time registry key under
 * `resources/js/pages/Panels/{Panel}/Columns/`, never markup and never a
 * path — the same rule custom widgets follow, and for the same reason: a
 * name resolved from data would be a way to render something the build never
 * saw.
 *
 * What the component receives is whatever `state()` returns, which must
 * serialize to scalars and arrays like every other cell.
 */
final class CustomColumn extends Column
{
    private string $component = '';

    /** @var (Closure(Model): mixed)|null */
    private ?Closure $stateUsing = null;

    public function type(): ColumnType
    {
        return ColumnType::Custom;
    }

    public function component(string $component): self
    {
        $this->component = $component;

        return $this;
    }

    /**
     * @param  Closure(Model): mixed  $callback
     */
    public function state(Closure $callback): self
    {
        $this->stateUsing = $callback;

        return $this;
    }

    public function toCell(Model $record): mixed
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
        return ['component' => $this->component];
    }
}
