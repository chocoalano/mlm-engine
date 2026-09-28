<?php

declare(strict_types=1);

namespace PandaPanel\Widgets\Support;

/**
 * How a chart draws itself.
 *
 * A closed set of settings rather than an arbitrary configuration object.
 * Chart.js takes a nested options tree because it is a library being
 * configured; this is a *description* of a chart, and the renderer that draws
 * it was compiled in. Passing options through would be passing behaviour
 * through, which is the one thing that never crosses here.
 *
 * The cost is that a chart has to be expressible in these terms. That is the
 * trade: no charting dependency, no configuration that can arrive from a
 * database, and a chart that renders the same everywhere.
 */
final class ChartOptions
{
    private bool $legend = true;

    private bool $grid = true;

    private bool $stacked = false;

    private bool $filled = false;

    private bool $curved = false;

    private bool $labels = false;

    private ?float $min = null;

    private ?float $max = null;

    private ?string $prefix = null;

    private ?string $suffix = null;

    public static function make(): self
    {
        return new self;
    }

    public function legend(bool $legend = true): self
    {
        $this->legend = $legend;

        return $this;
    }

    public function grid(bool $grid = true): self
    {
        $this->grid = $grid;

        return $this;
    }

    /**
     * Stacks the series rather than drawing them side by side.
     */
    public function stacked(bool $stacked = true): self
    {
        $this->stacked = $stacked;

        return $this;
    }

    /**
     * Fills the area under a line, which is what turns a trend into a volume.
     */
    public function filled(bool $filled = true): self
    {
        $this->filled = $filled;

        return $this;
    }

    public function curved(bool $curved = true): self
    {
        $this->curved = $curved;

        return $this;
    }

    /**
     * Prints each point's value on the chart.
     */
    public function labels(bool $labels = true): self
    {
        $this->labels = $labels;

        return $this;
    }

    /**
     * Pins the value axis.
     *
     * Worth setting when a chart is read against a target rather than against
     * itself: an axis that rescales to the data makes every week look the
     * same shape.
     */
    public function range(?float $min, ?float $max): self
    {
        $this->min = $min;
        $this->max = $max;

        return $this;
    }

    /**
     * What a value wears when it is written out — a currency symbol, a unit.
     */
    public function format(?string $prefix = null, ?string $suffix = null): self
    {
        $this->prefix = $prefix;
        $this->suffix = $suffix;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'legend' => $this->legend,
            'grid' => $this->grid,
            'stacked' => $this->stacked,
            'filled' => $this->filled,
            'curved' => $this->curved,
            'labels' => $this->labels,
            'min' => $this->min,
            'max' => $this->max,
            'prefix' => $this->prefix,
            'suffix' => $this->suffix,
        ];
    }
}
