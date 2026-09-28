<?php

declare(strict_types=1);

namespace PandaPanel\Widgets\Support;

use PandaPanel\Widgets\Enums\StatColor;

/**
 * One series on a chart widget.
 *
 * Immutable, like a `Stat`: the fluent methods return a new instance, so a
 * series cannot be changed after the widget handed it over.
 */
final readonly class ChartSeries
{
    /**
     * @param  list<int|float>  $values
     */
    public function __construct(
        public string $label,
        public array $values,
        public StatColor $color = StatColor::Default,
    ) {}

    /**
     * @param  list<int|float>  $values
     */
    public static function make(string $label, array $values): self
    {
        return new self($label, $values);
    }

    public function color(StatColor $color): self
    {
        return new self($this->label, $this->values, $color);
    }

    /**
     * @return array{label: string, values: list<int|float>, color: string}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'values' => $this->values,
            'color' => $this->color->value,
        ];
    }
}
