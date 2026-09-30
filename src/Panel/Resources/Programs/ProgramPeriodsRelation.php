<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Programs;

use Illuminate\Database\Eloquent\Model;
use PandaBear\Mlm\Models\CommissionPeriod;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Resources\Periods\CommissionPeriodResource;
use PandaBear\Mlm\Panel\Support\Display;
use PandaBear\Mlm\Panel\Support\MlmRelationManager;
use PandaPanel\Actions\ViewAction;
use PandaPanel\Tables\Columns\BadgeColumn;
use PandaPanel\Tables\Columns\DateTimeColumn;
use PandaPanel\Tables\Enums\SortDirection;
use PandaPanel\Tables\TableSchema;

final class ProgramPeriodsRelation extends MlmRelationManager
{
    protected static string $relationship = 'commissionPeriods';

    protected static ?string $key = 'commission-periods';

    protected static function viewPermission(): string
    {
        return MlmPermission::PERIODS_VIEW;
    }

    protected static function titleKey(): string
    {
        return 'periods';
    }

    public static function table(TableSchema $table, Model $owner): TableSchema
    {
        return $table
            ->columns([
                DateTimeColumn::make('from_at')->label(Display::field('from_at'))->sortable(),
                DateTimeColumn::make('until_at')->label(Display::field('until_at'))->sortable(),
                BadgeColumn::make('status')
                    ->label(Display::field('status'))
                    ->formatUsing(static fn (mixed $status, CommissionPeriod $period): string => Display::periodState($period))
                    ->colors(Display::statusColors('period'))
                    ->labels(Display::statusLabels('period')),
                DateTimeColumn::make('release_at')->label(Display::field('release_at'))->sortable(),
            ])
            ->defaultSort('from_at', SortDirection::Descending)
            ->recordActions([ViewAction::make(CommissionPeriodResource::class)]);
    }
}
