<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Columns;

use PandaPanel\Support\Format;
use PandaPanel\Tables\Enums\ColumnType;

final class DateTimeColumn extends DateColumn
{
    public function type(): ColumnType
    {
        return ColumnType::DateTime;
    }

    protected function defaultFormat(): string
    {
        return Format::dateTime();
    }
}
