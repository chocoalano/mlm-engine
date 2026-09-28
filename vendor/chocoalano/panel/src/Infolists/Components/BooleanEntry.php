<?php

declare(strict_types=1);

namespace PandaPanel\Infolists\Components;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Infolists\Enums\EntryType;

final class BooleanEntry extends Entry
{
    private string $trueLabel = 'Yes';

    private string $falseLabel = 'No';

    public function type(): EntryType
    {
        return EntryType::Boolean;
    }

    public function labels(string $true, string $false): self
    {
        $this->trueLabel = $true;
        $this->falseLabel = $false;

        return $this;
    }

    public function toValue(Model $record): bool
    {
        return (bool) $this->resolveValue($record);
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return [
            'trueLabel' => $this->trueLabel,
            'falseLabel' => $this->falseLabel,
        ];
    }
}
