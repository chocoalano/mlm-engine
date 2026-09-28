<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Columns;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Tables\Enums\Alignment;
use PandaPanel\Tables\Enums\ColumnType;

final class BooleanColumn extends Column
{
    private string $trueLabel = 'Yes';

    private string $falseLabel = 'No';

    protected Alignment $alignment = Alignment::Center;

    public function type(): ColumnType
    {
        return ColumnType::Boolean;
    }

    public function labels(string $true, string $false): self
    {
        $this->trueLabel = $true;
        $this->falseLabel = $false;

        return $this;
    }

    /**
     * @return array{value: bool, label: string}
     */
    public function toCell(Model $record): array
    {
        $value = (bool) $this->resolveValue($record);

        return [
            'value' => $value,
            'label' => $value ? $this->trueLabel : $this->falseLabel,
        ];
    }
}
