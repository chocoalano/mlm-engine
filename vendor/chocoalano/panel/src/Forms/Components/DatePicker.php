<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Components;

use Carbon\CarbonInterface;
use PandaPanel\Forms\Enums\FieldType;

final class DatePicker extends Field
{
    private ?string $minDate = null;

    private ?string $maxDate = null;

    public function type(): FieldType
    {
        return FieldType::Date;
    }

    public function minDate(?string $date): self
    {
        $this->minDate = $date;

        return $this;
    }

    public function maxDate(?string $date): self
    {
        $this->maxDate = $date;

        return $this;
    }

    /**
     * @return list<string>
     */
    protected function typeRules(): array
    {
        $rules = ['date'];

        if ($this->minDate !== null) {
            $rules[] = 'after_or_equal:'.$this->minDate;
        }

        if ($this->maxDate !== null) {
            $rules[] = 'before_or_equal:'.$this->maxDate;
        }

        return $rules;
    }

    /**
     * The control binds to `Y-m-d`, so a cast datetime is narrowed here
     * rather than in the browser.
     */
    protected function castForForm(mixed $value): ?string
    {
        return match (true) {
            $value instanceof CarbonInterface => $value->format('Y-m-d'),
            is_string($value) && $value !== '' => substr($value, 0, 10),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return [
            'minDate' => $this->minDate,
            'maxDate' => $this->maxDate,
        ];
    }
}
