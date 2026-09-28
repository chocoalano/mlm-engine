<?php

declare(strict_types=1);

namespace PandaPanel\Widgets;

use PandaPanel\Widgets\Enums\ChartVariant;
use PandaPanel\Widgets\Enums\WidgetType;
use PandaPanel\Widgets\Support\ChartOptions;
use PandaPanel\Widgets\Support\ChartSeries;

/**
 * A chart.
 *
 * Rendered by a dependency-free inline SVG on the frontend. That is a
 * deliberate trade rather than a gap: what crosses the wire is a description
 * of a chart — its points, its series, and a closed set of options — and the
 * renderer that draws it was compiled in. A charting library configured from
 * the server would mean configuration crossing as behaviour, which is the one
 * thing that never happens here.
 *
 * What that buys: no dependency, a chart that renders identically everywhere,
 * and options that cannot arrive from a database. What it costs: a chart has
 * to be expressible in `ChartOptions`. Anything beyond it is a `CustomWidget`,
 * which is honest about being bespoke.
 */
abstract class ChartWidget extends Widget
{
    protected static int|string|array $columnSpan = ['default' => 1, 'md' => 2, 'lg' => 2, 'xl' => 2];

    protected static ChartVariant $variant = ChartVariant::Bar;

    /**
     * How tall the plot is, in pixels.
     *
     * A chart with no height of its own grows with its container, and a
     * dashboard of them ends up a page of charts nobody can compare.
     */
    protected static int $maxHeight = 220;

    public static function type(): WidgetType
    {
        return WidgetType::Chart;
    }

    /**
     * One label per point, shared by every series.
     *
     * @return list<string>
     */
    abstract public function labels(): array;

    /**
     * @return list<ChartSeries>
     */
    abstract public function series(): array;

    /**
     * How it draws. Override to say something other than the defaults.
     */
    public function options(): ChartOptions
    {
        return ChartOptions::make();
    }

    /**
     * @return array{variant: string, labels: list<string>, series: list<array<string, mixed>>, options: array<string, mixed>, maxHeight: int}
     */
    public function data(): array
    {
        return [
            'variant' => static::$variant->value,
            'labels' => $this->labels(),
            'series' => array_map(
                static fn (ChartSeries $series): array => $series->toArray(),
                $this->series(),
            ),
            'options' => $this->options()->toArray(),
            'maxHeight' => static::$maxHeight,
        ];
    }
}
