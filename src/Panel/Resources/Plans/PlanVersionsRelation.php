<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Plans;

use Illuminate\Database\Eloquent\Model;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Support\Display;
use PandaBear\Mlm\Panel\Support\MlmRelationManager;
use PandaPanel\Actions\ViewAction;
use PandaPanel\Tables\Columns\BadgeColumn;
use PandaPanel\Tables\Columns\DateTimeColumn;
use PandaPanel\Tables\Columns\NumberColumn;
use PandaPanel\Tables\Enums\SortDirection;
use PandaPanel\Tables\TableSchema;

/**
 * A plan's versions. No lifecycle action: validating, publishing and
 * activating belong to the Plan Builder.
 */
final class PlanVersionsRelation extends MlmRelationManager
{
    protected static string $relationship = 'versions';

    protected static function viewPermission(): string
    {
        return MlmPermission::PLANS_VIEW;
    }

    protected static function titleKey(): string
    {
        return 'versions';
    }

    public static function table(TableSchema $table, Model $owner): TableSchema
    {
        return $table
            ->columns([
                NumberColumn::make('version')->label(Display::field('version'))->sortable(),
                BadgeColumn::make('status')
                    ->label(Display::field('status'))
                    ->colors(Display::statusColors('plan_version'))
                    ->labels(Display::statusLabels('plan_version')),
                DateTimeColumn::make('published_at')->label(Display::field('published_at'))->placeholder(Display::none()),
                DateTimeColumn::make('activated_at')->label(Display::field('activated_at'))->placeholder(Display::none()),
                DateTimeColumn::make('superseded_at')->label(Display::field('superseded_at'))->placeholder(Display::none()),
                NumberColumn::make('components_count')->label(Display::field('components_count'))->counts('components')->queryable(false),
            ])
            ->defaultSort('version', SortDirection::Descending)
            ->recordActions([ViewAction::make(PlanVersionResource::class)]);
    }
}
