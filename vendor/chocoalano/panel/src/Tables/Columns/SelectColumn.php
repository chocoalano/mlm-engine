<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Columns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use PandaPanel\Tables\Enums\ColumnType;

final class SelectColumn extends EditableColumn
{
    /** @var array<array-key, string> */
    private array $options = [];

    public function type(): ColumnType
    {
        return ColumnType::Select;
    }

    /**
     * @param  array<array-key, string>  $options
     */
    public function options(array $options): self
    {
        $this->options = $options;

        return $this;
    }

    /**
     * @return array{value: string, label: string, disabled: bool}
     */
    public function toCell(Model $record): array
    {
        $value = $this->resolveValue($record);
        $key = is_scalar($value) ? (string) $value : '';

        return [
            'value' => $key,
            'label' => $this->options[$key] ?? $key,
            'disabled' => $this->isDisabledFor($record),
        ];
    }

    /**
     * The declared options are the whitelist, and unlike a relation-backed
     * form select there is no bounded page to worry about: a table's inline
     * select shows every option it has.
     *
     * @return list<mixed>
     */
    protected function typeValidationRules(): array
    {
        return $this->options === []
            ? []
            : [Rule::in(array_map(strval(...), array_keys($this->options)))];
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        $options = [];

        foreach ($this->options as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => $label];
        }

        return [...parent::extraArray(), 'options' => $options];
    }
}
