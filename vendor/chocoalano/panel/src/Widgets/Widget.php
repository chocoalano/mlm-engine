<?php

declare(strict_types=1);

namespace PandaPanel\Widgets;

use Illuminate\Support\Str;
use LogicException;
use PandaPanel\Contracts\WidgetContract;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Widgets\Enums\WidgetType;
use PandaPanel\Widgets\Support\ColumnSpan;

/**
 * Base class for a dashboard or page widget.
 *
 * `canView()` is checked before `data()` is ever called, so an unauthorized
 * widget never runs its queries. That ordering is the point: a widget that
 * is merely hidden would still cost a query and could still leak counts
 * through timing.
 *
 * A widget marked lazy is delivered as a deferred Inertia prop so a slow
 * aggregate does not hold up the first paint of the whole dashboard.
 */
abstract class Widget implements WidgetContract
{
    protected static int $sort = 0;

    /** @var int|string|array<string, int|string> */
    protected static int|string|array $columnSpan = 1;

    protected static bool $lazy = false;

    protected static ?string $heading = null;

    protected static ?string $description = null;

    /**
     * How often the widget refreshes itself, in seconds.
     *
     * Null by default. Polling is a request every interval for every open
     * tab, which is worth it for a queue depth and absurd for a total that
     * changes twice a day — so it is opt-in per widget rather than a setting
     * somebody turns on once and forgets.
     */
    protected static ?int $pollingInterval = null;

    private ?PageContext $context = null;

    /** @var array<string, mixed> */
    private array $filters = [];

    abstract public static function type(): WidgetType;

    /**
     * Hands the widget the page it was placed on. Called by the page, never
     * by a widget.
     */
    public function withPageContext(PageContext $context): static
    {
        $this->context = $context;

        return $this;
    }

    /**
     * The record, query, and count of the page this widget sits on.
     *
     * Throws when there is none rather than returning an empty context: a
     * widget that reads a record it was never given is on the wrong page,
     * and saying so is more useful than a zero.
     */
    protected function context(): PageContext
    {
        return $this->context ?? throw new LogicException(
            static::class.' expects page context but was rendered without one. '
            .'Place it in headerWidgets() or footerWidgets() of a resource page.'
        );
    }

    /**
     * The filter values this widget was rendered with. Set by the page.
     *
     * @param  array<string, mixed>  $filters
     */
    public function withFilters(array $filters): static
    {
        $this->filters = $filters;

        return $this;
    }

    /**
     * One filter value.
     *
     * Already narrowed by the schema that declared it — a key the schema
     * never declared is not in here, whatever the query string said.
     */
    protected function filter(string $name, mixed $default = null): mixed
    {
        $value = $this->filters[$name] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    /**
     * @return array<string, mixed>
     */
    protected function filters(): array
    {
        return $this->filters;
    }

    /**
     * A form the widget is filtered by.
     *
     * Null for a widget that takes the page's filters and nothing more, which
     * is most of them. The schema is the whitelist: its values are read out of
     * the query string, narrowed by it, and persisted per widget.
     */
    public function filterSchema(): ?FormSchema
    {
        return null;
    }

    /**
     * Whether the filter form opens in a dialog rather than sitting above the
     * widget.
     *
     * Worth it for more than a control or two, where an inline form would be
     * bigger than the widget it filters.
     */
    public static function filtersInModal(): bool
    {
        return false;
    }

    /**
     * The payload the renderer receives. Scalars, arrays, and nulls only.
     *
     * @return array<string, mixed>
     */
    abstract public function data(): array;

    /**
     * Stable across runs so widget order and the deferred-data keys agree.
     */
    public static function id(): string
    {
        return Str::kebab(class_basename(static::class));
    }

    public static function sort(): int
    {
        return static::$sort;
    }

    public static function isLazy(): bool
    {
        return static::$lazy;
    }

    public static function heading(): ?string
    {
        return static::$heading;
    }

    public static function description(): ?string
    {
        return static::$description;
    }

    public static function pollingInterval(): ?int
    {
        return static::$pollingInterval;
    }

    /**
     * Open by default. A widget restricts itself by overriding this, and the
     * page it appears on authorizes independently.
     */
    public static function canView(): bool
    {
        return true;
    }

    /**
     * @return array{default: int|string, md: int|string, lg: int|string, xl: int|string}
     */
    public static function columnSpan(): array
    {
        return ColumnSpan::normalize(static::$columnSpan, static::class);
    }

    /**
     * The widget definition without its data.
     *
     * Lazy widgets are serialized through this alone; their data arrives
     * separately as a deferred prop.
     *
     * @return array<string, mixed>
     */
    public function toDefinition(): array
    {
        $schema = $this->filterSchema();

        return [
            'id' => static::id(),
            'type' => static::type()->value,
            'sort' => static::sort(),
            'columnSpan' => static::columnSpan(),
            'lazy' => static::isLazy(),
            'heading' => static::heading(),
            'description' => static::description(),
            // Seconds, or null. The frontend reloads the props the page gave
            // it rather than asking for one widget, because a widget's data
            // is a prop of the page it is on.
            'polling' => static::pollingInterval(),
            'filters' => $schema === null ? null : [
                'inModal' => static::filtersInModal(),
                'form' => $schema->toArrayWithState(null, $this->filters),
            ],
            'data' => static::isLazy() ? null : $this->data(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->toDefinition();
    }
}
