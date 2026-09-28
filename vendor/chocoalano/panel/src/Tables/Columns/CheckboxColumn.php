<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Columns;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Tables\Enums\Alignment;
use PandaPanel\Tables\Enums\ColumnType;

/**
 * The same write as a toggle, drawn as a checkbox.
 *
 * Two types rather than a flag because the control is the whole difference
 * and a `type` the frontend switches on is how every other column works.
 */
final class CheckboxColumn extends EditableColumn
{
    protected Alignment $alignment = Alignment::Center;

    public function type(): ColumnType
    {
        return ColumnType::Checkbox;
    }

    /**
     * @return array{value: bool, disabled: bool}
     */
    public function toCell(Model $record): array
    {
        return [
            'value' => (bool) $this->resolveValue($record),
            'disabled' => $this->isDisabledFor($record),
        ];
    }

    /**
     * @return list<mixed>
     */
    protected function typeValidationRules(): array
    {
        return ['boolean'];
    }

    protected function castForWrite(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
