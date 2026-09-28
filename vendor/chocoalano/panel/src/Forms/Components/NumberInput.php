<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Components;

use PandaPanel\Forms\Enums\FieldType;

final class NumberInput extends Field
{
    private bool $integer = false;

    private int|float|null $min = null;

    private int|float|null $max = null;

    private int|float|null $step = null;

    public function type(): FieldType
    {
        return FieldType::Number;
    }

    public function integer(bool $integer = true): self
    {
        $this->integer = $integer;

        return $this;
    }

    public function min(int|float|null $min): self
    {
        $this->min = $min;

        return $this;
    }

    public function max(int|float|null $max): self
    {
        $this->max = $max;

        return $this;
    }

    public function step(int|float|null $step): self
    {
        $this->step = $step;

        return $this;
    }

    /**
     * @return list<string>
     */
    protected function typeRules(): array
    {
        $rules = [$this->integer ? 'integer' : 'numeric'];

        if ($this->min !== null) {
            $rules[] = 'min:'.$this->min;
        }

        if ($this->max !== null) {
            $rules[] = 'max:'.$this->max;
        }

        return $rules;
    }

    protected function castForForm(mixed $value): int|float|null
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
            'step' => $this->step ?? ($this->integer ? 1 : null),
        ];
    }
}
