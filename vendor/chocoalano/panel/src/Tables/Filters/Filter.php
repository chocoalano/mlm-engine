<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Filters;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use PandaPanel\Support\Label;
use PandaPanel\Tables\Enums\FilterType;

/**
 * Base for every table filter.
 *
 * A filter is the only thing that may translate a URL value into a query
 * constraint, and it does so through `apply()`. A value that `sanitize()`
 * rejects never reaches the builder, which is what keeps `filters[...]` from
 * being an injection surface.
 */
abstract class Filter
{
    protected ?string $label = null;

    protected ?string $column = null;

    /** @var (Closure(Builder<covariant \Illuminate\Database\Eloquent\Model>, mixed): void)|null */
    protected ?Closure $queryUsing = null;

    /**
     * Applied to the query before anything else, outside the grouping the
     * ordinary constraint runs in.
     *
     * For a filter that has to change what the query *is* rather than narrow
     * what it returns — lifting a global scope, joining a table the other
     * constraints then read. Kept separate because an `orWhere` in the same
     * group as a search would widen it.
     *
     * @var (Closure(Builder<covariant \Illuminate\Database\Eloquent\Model>, mixed): void)|null
     */
    protected ?Closure $baseQueryUsing = null;

    /**
     * The value applied when the request says nothing about this filter.
     *
     * Distinct from "no value": a default is a decision the table made, so
     * the frontend shows it as active and clearing it is an explicit act.
     */
    protected mixed $default = null;

    protected bool $hasDefault = false;

    final public function __construct(protected readonly string $name) {}

    public static function make(string $name): static
    {
        return new static($name);
    }

    abstract public function type(): FilterType;

    /**
     * Returns null when the value is not acceptable for this filter, which
     * makes the filter a no-op for that request.
     */
    abstract public function sanitize(mixed $value): mixed;

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    abstract protected function constrain(Builder $query, mixed $value): void;

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function column(string $column): static
    {
        $this->column = $column;

        return $this;
    }

    /**
     * Replaces the default constraint. The closure runs backend-side and
     * receives only the sanitized value.
     *
     * @param  Closure(Builder<covariant \Illuminate\Database\Eloquent\Model>, mixed): void  $callback
     */
    public function query(Closure $callback): static
    {
        $this->queryUsing = $callback;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->label ?? Label::resolve(
            'fields',
            $this->name,
            fn (): string => Str::headline($this->name),
        );
    }

    public function getColumn(): string
    {
        return $this->column ?? $this->name;
    }

    /**
     * @param  Closure(Builder<covariant \Illuminate\Database\Eloquent\Model>, mixed): void  $callback
     */
    public function modifyBaseQueryUsing(Closure $callback): static
    {
        $this->baseQueryUsing = $callback;

        return $this;
    }

    /**
     * The value this filter starts on.
     */
    public function default(mixed $value): static
    {
        $this->default = $value;
        $this->hasDefault = true;

        return $this;
    }

    public function hasDefault(): bool
    {
        return $this->hasDefault;
    }

    public function getDefault(): mixed
    {
        return $this->default;
    }

    /**
     * Applies the part of this filter that shapes the query itself.
     *
     * Outside the constraint grouping and before the search, so a filter that
     * joins a table or lifts a scope has done so by the time anything reads
     * it.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    final public function applyBaseQuery(Builder $query, mixed $value): void
    {
        if ($this->baseQueryUsing === null) {
            return;
        }

        $sanitized = $this->sanitize($value);

        if ($sanitized === null) {
            return;
        }

        ($this->baseQueryUsing)($query, $sanitized);
    }

    /**
     * How this filter's active value reads to a human, for the chip that says
     * what is narrowing the table.
     *
     * Built on the server because only the filter knows what its value means:
     * `1` is "Verified", not "1".
     */
    public function indicator(mixed $value): ?string
    {
        $sanitized = $this->sanitize($value);

        if ($sanitized === null) {
            return null;
        }

        return $this->getLabel().': '.$this->describe($sanitized);
    }

    /**
     * The human reading of a sanitized value. Overridden by a filter whose
     * value is not its own label.
     */
    protected function describe(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    final public function apply(Builder $query, mixed $value): void
    {
        $sanitized = $this->sanitize($value);

        if ($sanitized === null) {
            return;
        }

        if ($this->queryUsing !== null) {
            ($this->queryUsing)($query, $sanitized);

            return;
        }

        // A filter that only modifies the base query has already said where
        // its work happens. Falling through to the default constraint would
        // invent a `where` on a column named after the filter — which is
        // rarely a column at all when the filter's job was to change the
        // query's shape.
        if ($this->baseQueryUsing !== null) {
            return;
        }

        $this->constrain($query, $sanitized);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->getLabel(),
            'type' => $this->type()->value,
            'default' => $this->hasDefault ? $this->default : null,
            ...$this->extraArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return [];
    }
}
