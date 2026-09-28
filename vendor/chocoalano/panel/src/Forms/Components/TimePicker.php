<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Components;

use Carbon\CarbonInterface;
use PandaPanel\Forms\Enums\FieldType;

/**
 * A time of day, with no date attached.
 */
final class TimePicker extends Field
{
    private bool $seconds = false;

    public function type(): FieldType
    {
        return FieldType::Time;
    }

    public function seconds(bool $seconds = true): self
    {
        $this->seconds = $seconds;

        return $this;
    }

    /**
     * @return list<mixed>
     */
    protected function typeRules(): array
    {
        return ['date_format:'.($this->seconds ? 'H:i:s' : 'H:i')];
    }

    protected function castForForm(mixed $value): ?string
    {
        $format = $this->seconds ? 'H:i:s' : 'H:i';

        if ($value instanceof CarbonInterface) {
            return $value->format($format);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return ['seconds' => $this->seconds];
    }
}
