<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Components;

use PandaPanel\Forms\Enums\FieldType;

final class Textarea extends Field
{
    private int $rows = 4;

    private ?int $maxLength = null;

    public function type(): FieldType
    {
        return FieldType::Textarea;
    }

    public function rows(int $rows): self
    {
        $this->rows = max(1, $rows);

        return $this;
    }

    public function maxLength(?int $length): self
    {
        $this->maxLength = $length;

        return $this;
    }

    /**
     * @return list<string>
     */
    protected function typeRules(): array
    {
        return $this->maxLength === null
            ? ['string']
            : ['string', 'max:'.$this->maxLength];
    }

    protected function castForForm(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return [
            'rows' => $this->rows,
            'maxLength' => $this->maxLength,
        ];
    }
}
