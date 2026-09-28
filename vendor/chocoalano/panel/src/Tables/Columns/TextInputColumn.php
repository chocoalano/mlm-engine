<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Columns;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Tables\Enums\ColumnType;

final class TextInputColumn extends EditableColumn
{
    private string $inputType = 'text';

    private ?int $maxLength = null;

    public function type(): ColumnType
    {
        return ColumnType::TextInput;
    }

    public function numeric(): self
    {
        $this->inputType = 'number';

        return $this;
    }

    public function maxLength(int $length): self
    {
        $this->maxLength = $length;

        return $this;
    }

    /**
     * @return array{value: string, disabled: bool}
     */
    public function toCell(Model $record): array
    {
        $value = $this->resolveValue($record);

        return [
            'value' => is_scalar($value) ? (string) $value : '',
            'disabled' => $this->isDisabledFor($record),
        ];
    }

    /**
     * @return list<mixed>
     */
    protected function typeValidationRules(): array
    {
        $rules = $this->inputType === 'number' ? ['numeric'] : ['string'];

        if ($this->maxLength !== null) {
            $rules[] = 'max:'.$this->maxLength;
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return [
            ...parent::extraArray(),
            'inputType' => $this->inputType,
            'maxLength' => $this->maxLength,
        ];
    }
}
