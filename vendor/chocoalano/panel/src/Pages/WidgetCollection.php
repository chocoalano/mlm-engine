<?php

declare(strict_types=1);

namespace PandaPanel\Pages;

use Closure;
use Inertia\Inertia;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Widgets\PageContext;
use PandaPanel\Widgets\Support\WidgetFilters;
use PandaPanel\Widgets\Widget;

/**
 * Resolves a page's widgets into props.
 *
 * Authorization runs first and once, so a widget the user may not see never
 * has `data()` called and therefore never runs a query.
 *
 * Eager widgets carry their data inline. Lazy ones ship a definition with
 * null data plus a single deferred prop holding every lazy widget's payload,
 * so a slow aggregate delays only itself rather than the first paint.
 */
final readonly class WidgetCollection
{
    /**
     * @param  list<Widget>  $widgets
     */
    private function __construct(private array $widgets) {}

    /**
     * @param  list<class-string<Widget>>  $classes
     */
    public static function for(
        array $classes,
        ?PageContext $context = null,
        ?WidgetFilters $filters = null,
    ): self {
        $widgets = [];

        foreach ($classes as $class) {
            if (! $class::canView()) {
                continue;
            }

            $widget = new $class;

            if ($context !== null) {
                $widget->withPageContext($context);
            }

            if ($filters !== null) {
                $widget->withFilters($filters->for($class::id()));
            }

            $widgets[] = $widget;
        }

        usort(
            $widgets,
            static fn (Widget $a, Widget $b): int => [$a::sort(), $a::id()] <=> [$b::sort(), $b::id()],
        );

        return new self($widgets);
    }

    /**
     * One collection covering both, for the single deferred prop a page
     * sends. Header and footer keep their own collections for the
     * definitions, because the page renders them in two places.
     */
    public function merge(self $other): self
    {
        return new self([...$this->widgets, ...$other->widgets]);
    }

    /**
     * The filter schemas the widgets declare, keyed by id.
     *
     * Read before the state is, because the schema is what narrows it: a
     * filter value is only kept when a widget said it exists.
     *
     * @param  list<class-string<Widget>>  $classes
     * @return array<string, FormSchema>
     */
    public static function filterSchemas(array $classes): array
    {
        $schemas = [];

        foreach ($classes as $class) {
            if (! $class::canView()) {
                continue;
            }

            $schema = (new $class)->filterSchema();

            if ($schema !== null) {
                $schemas[$class::id()] = $schema;
            }
        }

        return $schemas;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        return array_map(
            static fn (Widget $widget): array => $widget->toDefinition(),
            $this->widgets,
        );
    }

    /**
     * A deferred prop holding `{widgetId: data}` for the lazy widgets, or
     * null when there are none so the page does not advertise a second
     * request it will never make.
     */
    public function deferred(): mixed
    {
        $lazy = array_values(array_filter(
            $this->widgets,
            static fn (Widget $widget): bool => $widget::isLazy(),
        ));

        if ($lazy === []) {
            return null;
        }

        return Inertia::defer($this->resolver($lazy));
    }

    /**
     * @param  list<Widget>  $lazy
     * @return Closure(): array<string, mixed>
     */
    private function resolver(array $lazy): Closure
    {
        return static function () use ($lazy): array {
            $data = [];

            foreach ($lazy as $widget) {
                $data[$widget::id()] = $widget->data();
            }

            return $data;
        };
    }
}
