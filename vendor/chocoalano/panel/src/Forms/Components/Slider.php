<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Components;

use PandaPanel\Forms\Enums\FieldType;

/**
 * A number chosen by dragging.
 *
 * The bounds are the validation, not decoration: a slider that could not be
 * dragged past 100 but accepted 1000 from a crafted request would be a
 * control pretending to be a constraint.
 */
final class Slider extends Field
{
    private float $min = 0;

    private float $max = 100;

    private float $step = 1;

    private bool $showValue = true;

    public function type(): FieldType
    {
        return FieldType::Slider;
    }

    public function range(float $min, float $max, float $step = 1): self
    {
        $this->min = $min;
        $this->max = $max;
        $this->step = $step > 0 ? $step : 1;

        return $this;
    }

    public function showValue(bool $show = true): self
    {
        $this->showValue = $show;

        return $this;
    }

    /**
     * @return list<mixed>
     */
    protected function typeRules(): array
    {
        return ['numeric', 'min:'.$this->min, 'max:'.$this->max];
    }

    protected function castForForm(mixed $value): float|int|null
    {
        return is_numeric($value) ? $value + 0 : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return [
            'min' => $this->min,
            'max' => $this->max,
            'step' => $this->step,
            'showValue' => $this->showValue,
        ];
    }
}
