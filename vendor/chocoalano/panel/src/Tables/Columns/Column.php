<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Columns;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PandaPanel\Actions\Action;
use PandaPanel\Exceptions\PanelSchemaException;
use PandaPanel\Support\Label;
use PandaPanel\Support\SafeUrl;
use PandaPanel\Tables\Columns\Concerns\HasRelationshipState;
use PandaPanel\Tables\Enums\Alignment;
use PandaPanel\Tables\Enums\ColumnPin;
use PandaPanel\Tables\Enums\ColumnType;
use PandaPanel\Tables\Enums\SortDirection;
use PandaPanel\Tables\Filters\Constraints\BooleanConstraint;
use PandaPanel\Tables\Filters\Constraints\Constraint;
use PandaPanel\Tables\Filters\Constraints\DateConstraint;
use PandaPanel\Tables\Filters\Constraints\NumberConstraint;
use PandaPanel\Tables\Filters\Constraints\TextConstraint;
use PandaPanel\Tables\Summaries\Summarizer;

/**
 * Base for every table column.
 *
 * A column owns three things: how it is described to the frontend
 * (`toArray()`), how a record becomes a serializable cell (`toCell()`), and
 * what a record makes of the per-row extras — tooltip, link, attributes —
 * that cannot be part of a shared definition (`toCellMeta()`). None of them
 * may leak a closure, a model, or a query.
 *
 * Anything that can vary per record accepts a closure, evaluated on the
 * server, with only the result crossing the wire. Anything that cannot is a
 * plain value on the definition and is sent once rather than per row.
 *
 * `sortable()` and `searchable()` are declarations, not behaviour. The query
 * layer reads them as a whitelist, which is what keeps a URL parameter from
 * reaching the query builder unchecked.
 */
abstract class Column
{
    /**
     * Every column can read a relation. Aggregates and relation sorting are
     * the same idea applied to any cell type, so they belong on the base
     * rather than on a `RelationshipColumn` that would have to reimplement
     * text, number, and badge rendering to be useful.
     */
    use HasRelationshipState;

    protected ?string $label = null;

    protected bool $sortable = false;

    protected bool $searchable = false;

    protected bool $visible = true;

    protected bool $toggleable = true;

    protected Alignment $alignment = Alignment::Start;

    /** Null follows the cell alignment, which is what a header usually wants. */
    protected ?Alignment $headerAlignment = null;

    protected ?string $sortColumn = null;

    /** @var list<string>|null */
    protected ?array $searchColumns = null;

    /**
     * Whether this column gets its own search box as well as taking part in
     * the table-wide one.
     */
    protected bool $individuallySearchable = false;

    /** @var (Closure(Builder<covariant Model>, SortDirection): void)|null */
    protected ?Closure $sortUsing = null;

    /** @var (Closure(mixed, Model): mixed)|null */
    protected ?Closure $formatUsing = null;

    /**
     * What is shown when the cell has no value.
     *
     * On the base class rather than per type, so an empty date and an empty
     * text column read the same way, and Vue has one rule for "there is
     * nothing here" instead of one per renderer.
     */
    protected ?string $placeholder = null;

    /**
     * Substituted for a null value *before* the cell is built, so it goes
     * through the same formatting the real value would.
     *
     * Different from `placeholder()`, which is presentation for the absence
     * itself: a default state means "treat it as this", a placeholder means
     * "say it is missing".
     */
    protected mixed $default = null;

    /** @var (Closure(Model): ?string)|string|null */
    protected Closure|string|null $tooltip = null;

    /** Static, because a header has no record to vary by. */
    protected ?string $headerTooltip = null;

    protected bool $wrapHeader = false;

    /**
     * A CSS length, applied as an inline width.
     *
     * Not a Tailwind class: `w-${n}` would have to be interpolated, and an
     * interpolated class does not exist in the bundle.
     */
    protected ?string $width = null;

    /**
     * Which edge this column stays pinned to while the table scrolls
     * sideways, or null for a column that scrolls with everything else.
     */
    protected ?ColumnPin $frozen = null;

    /** @var (Closure(Model): array<array-key, mixed>)|array<array-key, mixed> */
    protected Closure|array $extraAttributes = [];

    /** @var (Closure(Model): ?string)|null */
    protected ?Closure $urlUsing = null;

    /** @var list<Summarizer> */
    protected array $summarizers = [];

    /**
     * An action the whole cell runs when clicked.
     *
     * Distinct from `url()`, which navigates: this executes something. Both
     * make the cell interactive, and a column may only have one of them —
     * a cell that both went somewhere and did something would be a coin toss.
     */
    protected ?Action $action = null;

    final public function __construct(protected readonly string $name)
    {
        if (trim($name) === '') {
            throw PanelSchemaException::emptyName('column');
        }
    }

    public static function make(string $name): static
    {
        return new static($name);
    }

    abstract public function type(): ColumnType;

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    /**
     * @param  string|null  $column  the database column to order by, when it differs from the attribute name
     */
    public function sortable(bool $sortable = true, ?string $column = null): static
    {
        $this->sortable = $sortable;
        $this->sortColumn = $column;

        return $this;
    }

    /**
     * @param  list<string>|null  $columns  the database columns to match, when they differ from the attribute name
     * @param  bool  $individually  also give this column its own search box
     */
    public function searchable(
        bool $searchable = true,
        ?array $columns = null,
        bool $individually = false,
    ): static {
        $this->searchable = $searchable;
        $this->searchColumns = $columns;
        $this->individuallySearchable = $individually;

        return $this;
    }

    /**
     * Replaces how this column sorts.
     *
     * For an ordering the schema cannot express as a column name — a
     * `CASE` over a status, a computed distance, a JSON path. The closure
     * runs backend-side and receives the direction the request asked for,
     * already validated.
     *
     * @param  Closure(Builder<covariant Model>, SortDirection): void  $callback
     */
    public function sortUsing(Closure $callback): static
    {
        $this->sortable = true;
        $this->sortUsing = $callback;

        return $this;
    }

    public function isIndividuallySearchable(): bool
    {
        return $this->searchable && $this->individuallySearchable;
    }

    public function hasCustomSort(): bool
    {
        return $this->sortUsing !== null;
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    public function applyCustomSort(Builder $query, SortDirection $direction): void
    {
        if ($this->sortUsing !== null) {
            ($this->sortUsing)($query, $direction);
        }
    }

    /**
     * Whether this column can be used as a query-builder condition.
     *
     * Null means "decide from the type", which is what makes an ordinary
     * `TextColumn::make('name')` queryable without a second declaration
     * beside it. Set explicitly to force the answer either way.
     */
    private ?bool $queryable = null;

    /**
     * A constraint supplied by the developer for this column.
     *
     * The escape hatch for a column whose data means something the type
     * cannot express — a text column holding an enum, or a custom column
     * whose value only the application understands.
     */
    private ?Constraint $queryConstraint = null;

    /**
     * Whether this column offers itself as a query-builder condition.
     *
     * A column is queryable when its type maps onto a constraint the package
     * knows how to build a query from. Display-only types — an image, an
     * icon, a colour swatch — are not, because there is nothing behind them
     * to compare against; a custom column is not either, because its value is
     * whatever the application decided and guessing at it would produce a
     * filter that silently matches nothing.
     */
    public function queryable(bool $queryable = true): static
    {
        $this->queryable = $queryable;

        return $this;
    }

    /**
     * Replaces the constraint derived from this column's type.
     *
     * The name is taken from the column, so a constraint made for one column
     * cannot end up describing another.
     */
    public function queryConstraint(Constraint $constraint): static
    {
        $this->queryConstraint = $constraint;
        $this->queryable = true;

        return $this;
    }

    /**
     * The constraint this column contributes, or null for none.
     *
     * Derived rather than declared, so the columns a table already lists are
     * the conditions it can be filtered by. `QueryBuilderFilter` merges these
     * under any constraint the developer declared explicitly, which keeps one
     * list authoritative instead of two that drift.
     */
    public function toQueryConstraint(): ?Constraint
    {
        if ($this->queryable === false) {
            return null;
        }

        if ($this->queryConstraint !== null) {
            return $this->queryConstraint->label($this->getLabel());
        }

        $constraint = $this->defaultQueryConstraint();

        if ($constraint === null) {
            return null;
        }

        return $constraint->label($this->getLabel());
    }

    /**
     * What this column's type means as a comparison.
     *
     * A closed match rather than a lookup table with a fallback: a type that
     * is not listed contributes nothing, and adding a column type is then a
     * decision about whether it can be queried rather than an accident.
     */
    private function defaultQueryConstraint(): ?Constraint
    {
        $name = $this->name;

        return match ($this->type()) {
            ColumnType::Text,
            ColumnType::Badge,
            ColumnType::TextInput,
            ColumnType::Select => TextConstraint::make($name),
            ColumnType::Number => NumberConstraint::make($name),
            ColumnType::Date,
            ColumnType::DateTime => DateConstraint::make($name),
            ColumnType::Boolean,
            ColumnType::Toggle,
            ColumnType::Checkbox => BooleanConstraint::make($name),
            // Image, Icon, Color and Custom draw something from a value
            // rather than showing it, so there is no comparison to offer.
            // A custom column can still opt in with `queryConstraint()`.
            default => null,
        };
    }

    public function visible(bool $visible = true): static
    {
        $this->visible = $visible;

        return $this;
    }

    /**
     * A column that is not toggleable cannot be hidden by the user. Use it
     * for the column that identifies the record.
     */
    public function toggleable(bool $toggleable = true): static
    {
        $this->toggleable = $toggleable;

        return $this;
    }

    public function alignment(Alignment|string $alignment): static
    {
        $this->alignment = Alignment::fromRequest($alignment) ?? Alignment::Start;

        return $this;
    }

    /**
     * Aligns the header independently of the cells — a numeric column reads
     * best right-aligned, and its header usually wants to follow, but a long
     * heading over narrow figures does not.
     */
    public function headerAlignment(Alignment|string $alignment): static
    {
        $this->headerAlignment = Alignment::fromRequest($alignment);

        return $this;
    }

    /**
     * Shown in place of an empty cell.
     */
    public function placeholder(string $placeholder): static
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    /**
     * Stands in for a null value before the cell is formatted.
     */
    public function default(mixed $default): static
    {
        $this->default = $default;

        return $this;
    }

    /**
     * @param  (Closure(Model): ?string)|string  $tooltip
     */
    public function tooltip(Closure|string $tooltip): static
    {
        $this->tooltip = $tooltip;

        return $this;
    }

    public function headerTooltip(string $tooltip): static
    {
        $this->headerTooltip = $tooltip;

        return $this;
    }

    public function wrapHeader(bool $wrap = true): static
    {
        $this->wrapHeader = $wrap;

        return $this;
    }

    /**
     * @param  string  $width  any CSS length, such as `12rem` or `20%`
     */
    public function width(string $width): static
    {
        $this->width = $width;

        return $this;
    }

    /**
     * Keeps this column in view while the rest of the table scrolls sideways.
     *
     * The reason a wide table is usable at all: without it, scrolling to
     * column fourteen means scrolling the name that identifies the row off
     * the screen, and every cell after that is a value with nothing attached
     * to it.
     *
     * ```php
     * TextColumn::make('name')->frozen(),                 // to the left edge
     * TextColumn::make('total')->frozen(ColumnPin::End),  // to the right
     * ```
     *
     * Freezing a column freezes everything structural on the same side of it
     * — the reorder handle and the selection checkbox to the left, the row
     * actions to the right if the table asked for those — because a frozen
     * column with a scrolling checkbox beside it is two things that disagree
     * about where the row starts.
     *
     * The offsets are worked out in the browser from the widths the columns
     * actually take, so a frozen column does not have to declare a `width()`
     * and one that is resized stays lined up.
     */
    public function frozen(ColumnPin|bool $pin = true): static
    {
        $this->frozen = match (true) {
            $pin === false => null,
            $pin === true => ColumnPin::Start,
            default => $pin,
        };

        return $this;
    }

    public function getFrozen(): ?ColumnPin
    {
        return $this->frozen;
    }

    /**
     * Extra HTML attributes on the cell.
     *
     * Scalars only, and event handlers are refused: these are spread onto an
     * element, so an `onclick` here would be a way to put executable content
     * on a page from a schema. Presentation attributes are what this is for.
     *
     * @param  (Closure(Model): array<array-key, mixed>)|array<array-key, mixed>  $attributes
     */
    public function extraAttributes(Closure|array $attributes): static
    {
        $this->extraAttributes = $attributes;

        return $this;
    }

    /**
     * Turns the cell into a link. The URL is produced on the server, so a row
     * never carries anything the client has to resolve.
     *
     * @param  Closure(Model): ?string  $callback
     */
    public function url(Closure $callback): static
    {
        $this->urlUsing = $callback;

        return $this;
    }

    /**
     * Makes the cell run an action when clicked.
     *
     * The action is authorized per record like any other, so a cell the user
     * may not act on is rendered as an ordinary value rather than as a button
     * that answers 403.
     */
    public function action(Action $action): static
    {
        $this->action = $action;

        return $this;
    }

    public function getAction(): ?Action
    {
        return $this->action;
    }

    /**
     * Runs backend-side. Only its scalar result is serialized.
     *
     * @param  Closure(mixed, Model): mixed  $callback
     */
    public function formatUsing(Closure $callback): static
    {
        $this->formatUsing = $callback;

        return $this;
    }

    /**
     * Figures shown under this column.
     *
     * @param  array<array-key, Summarizer>  $summarizers
     */
    public function summarize(array $summarizers): static
    {
        $this->summarizers = array_values($summarizers);

        return $this;
    }

    /**
     * @return list<Summarizer>
     */
    public function getSummarizers(): array
    {
        return $this->summarizers;
    }

    public function hasSummaries(): bool
    {
        return $this->summarizers !== [];
    }

    /**
     * The column a summary aggregates.
     *
     * The aggregate attribute when the column is one, so summing a
     * `posts_count` column sums what the query actually selected.
     */
    public function summaryColumn(): string
    {
        return $this->aggregateAttribute() ?? $this->getSortColumn();
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

    public function isSortable(): bool
    {
        return $this->sortable;
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }

    public function isToggleable(): bool
    {
        return $this->toggleable;
    }

    public function isSearchable(): bool
    {
        return $this->searchable;
    }

    /**
     * The database column to order by.
     *
     * A dotted name is left alone: sorting across a relation needs its own
     * strategy, which `applySort()` supplies, and silently sorting by a
     * column that does not exist would be worse than not sorting.
     */
    public function getSortColumn(): string
    {
        return $this->sortColumn ?? $this->name;
    }

    /**
     * @return list<string>
     */
    public function getSearchColumns(): array
    {
        return $this->searchColumns ?? [$this->name];
    }

    /**
     * Reads the attribute, supporting dot notation for relations.
     */
    public function resolveValue(Model $record): mixed
    {
        $value = data_get($record, $this->stateAttribute()) ?? $this->default;

        if ($this->formatUsing !== null) {
            return ($this->formatUsing)($value, $record);
        }

        return $value;
    }

    /**
     * The serialized cell for one record.
     */
    abstract public function toCell(Model $record): mixed;

    /**
     * The per-row extras, or null when this column has none.
     *
     * Kept beside the cells rather than inside them so the cell value stays
     * exactly the shape each renderer's guard already narrows, and a table
     * whose columns use none of this pays nothing for it.
     *
     * @return array<string, mixed>|null
     */
    public function toCellMeta(Model $record): ?array
    {
        $meta = [];

        $tooltip = $this->resolveTooltip($record);

        if ($tooltip !== null) {
            $meta['tooltip'] = $tooltip;
        }

        if ($this->urlUsing !== null) {
            $url = SafeUrl::sanitize(($this->urlUsing)($record));

            if (is_string($url) && $url !== '') {
                $meta['url'] = $url;
            }
        }

        $attributes = $this->resolveExtraAttributes($record);

        if ($attributes !== []) {
            $meta['attributes'] = $attributes;
        }

        // Resolved per record, so a cell the user may not act on is an
        // ordinary value rather than a button that answers 403.
        if ($this->action !== null) {
            $definition = $this->action->toArray($record);

            if ($definition !== null) {
                $meta['action'] = $definition;
            }
        }

        return $meta === [] ? null : $meta;
    }

    private function resolveTooltip(Model $record): ?string
    {
        if ($this->tooltip === null) {
            return null;
        }

        $tooltip = $this->tooltip instanceof Closure
            ? ($this->tooltip)($record)
            : $this->tooltip;

        return is_string($tooltip) && $tooltip !== '' ? $tooltip : null;
    }

    /**
     * @return array<string, scalar>
     */
    private function resolveExtraAttributes(Model $record): array
    {
        // Whatever a closure returns is unverified: the signature is a
        // promise, not a fact, and this is the value that reaches an element.
        $attributes = $this->extraAttributes instanceof Closure
            ? ($this->extraAttributes)($record)
            : $this->extraAttributes;

        $safe = [];

        foreach ($attributes as $key => $value) {
            // Event handlers would put executable content on the page from a
            // schema, which is the one thing no serialized value may do.
            if (! is_string($key) || ! is_scalar($value) || str_starts_with(strtolower($key), 'on')) {
                continue;
            }

            $safe[$key] = $value;
        }

        return $safe;
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
            'sortable' => $this->sortable,
            'searchable' => $this->searchable,
            'individuallySearchable' => $this->isIndividuallySearchable(),
            'visible' => $this->visible,
            'toggleable' => $this->toggleable,
            'alignment' => $this->alignment->value,
            'headerAlignment' => ($this->headerAlignment ?? $this->alignment)->value,
            'placeholder' => $this->placeholder,
            'headerTooltip' => $this->headerTooltip,
            'wrapHeader' => $this->wrapHeader,
            'width' => $this->width,
            'frozen' => $this->frozen?->value,
            ...$this->extraArray(),
        ];
    }

    /**
     * Column-type-specific metadata merged into the definition.
     *
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return [];
    }
}
