<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Support;

use PandaBear\Mlm\Models\Program;
use PandaPanel\Tables\Filters\SelectFilter;

/**
 * Narrows a list to one program — the only boundary there is — so a
 * program's page can link to its members, plans, periods, wallets and
 * payouts rather than loading them all itself.
 */
final class ProgramFilter
{
    public static function make(string $column = 'program_id'): SelectFilter
    {
        return SelectFilter::make('program_id')
            ->column($column)
            ->label(Display::field('program'))
            ->options(Program::query()->orderBy('code')->pluck('name', 'id')->all());
    }
}
