<?php

declare(strict_types=1);

namespace PandaPanel\Tables;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PandaPanel\Support\Label;
use PandaPanel\Tables\Enums\SortDirection;

/**
 * A way of banding rows together under headings.
 *
 * Presentation, not aggregation: grouping does not change which records the
 * query returns or how many, so paging still works and a group can be split
 * across two pages exactly as any run of rows can. That is the honest
 * behaviour for a server-paginated table — collapsing the whole result into
 * groups would mean fetching all of it.
 *
 * What grouping *does* change is the ordering: rows must arrive together to
 * be shown together, so an active group sorts before anything else.
 */
final class Group
{
    private ?string $label = null;

    private ?string $column = null;

    private SortDirection $direction = SortDirection::Ascending;

    /** @var (Closure(Model): ?string)|null */
    private ?Closure $titleUsing = null;

    /** @var (Closure(Model): ?string)|null */
    private ?Closure $descriptionUsing = null;

    public function __construct(private readonly string $name) {}

    public static function make(string $name): self
    {
        return new self($name);
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * The column rows are banded by, when it differs from the name.
     */
    public function column(string $column): self
    {
        $this->column = $column;

        return $this;
    }

    public function direction(SortDirection $direction): self
    {
        $this->direction = $direction;

        return $this;
    }

    /**
     * The heading a group shows. Resolved per record on the server, so a key
     * can become a name without the frontend knowing how.
     *
     * @param  Closure(Model): ?string  $callback
     */
    public function titleUsing(Closure $callback): self
    {
        $this->titleUsing = $callback;

        return $this;
    }

    /**
     * @param  Closure(Model): ?string  $callback
     */
    public function descriptionUsing(Closure $callback): self
    {
        $this->descriptionUsing = $callback;

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
     * Which band a record falls in.
     *
     * The raw value, not the title: two records with the same key belong
     * together even if a title closure would render them differently.
     */
    public function keyFor(Model $record): string
    {
        $value = data_get($record, $this->getColumn());

        return is_scalar($value) ? (string) $value : '';
    }

    public function titleFor(Model $record): string
    {
        if ($this->titleUsing !== null) {
            return (string) ($this->titleUsing)($record);
        }

        $key = $this->keyFor($record);

        return $key === '' ? 'Ungrouped' : $key;
    }

    public function descriptionFor(Model $record): ?string
    {
        return $this->descriptionUsing === null
            ? null
            : ($this->descriptionUsing)($record);
    }

    /**
     * Rows have to arrive together to be shown together, so the group sorts
     * before whatever else the table is ordered by.
     *
     * @param  Builder<covariant Model>  $query
     */
    public function applySort(Builder $query): void
    {
        $query->orderBy($this->getColumn(), $this->direction->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->getLabel(),
        ];
    }
}
