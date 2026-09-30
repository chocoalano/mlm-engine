<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Programs;

use Illuminate\Database\Eloquent\Model;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Resources\Plans\PlanResource;
use PandaBear\Mlm\Panel\Support\Display;
use PandaBear\Mlm\Panel\Support\MlmRelationManager;
use PandaPanel\Actions\ViewAction;
use PandaPanel\Tables\Columns\NumberColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Enums\SortDirection;
use PandaPanel\Tables\TableSchema;

final class ProgramPlansRelation extends MlmRelationManager
{
    protected static string $relationship = 'plans';

    protected static function viewPermission(): string
    {
        return MlmPermission::PLANS_VIEW;
    }

    protected static function titleKey(): string
    {
        return 'plans';
    }

    public static function table(TableSchema $table, Model $owner): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('code')->label(Display::field('code'))->searchable()->sortable(),
                TextColumn::make('name')->label(Display::field('name'))->searchable()->sortable(),
                NumberColumn::make('versions_count')->label(Display::field('versions_count'))->counts('versions')->queryable(false),
            ])
            ->defaultSort('code', SortDirection::Ascending)
            ->recordActions([ViewAction::make(PlanResource::class)]);
    }
}
