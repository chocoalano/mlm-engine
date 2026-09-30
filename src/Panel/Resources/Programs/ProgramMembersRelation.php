<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Programs;

use Illuminate\Database\Eloquent\Model;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Resources\Members\MemberResource;
use PandaBear\Mlm\Panel\Support\Display;
use PandaBear\Mlm\Panel\Support\MlmRelationManager;
use PandaPanel\Actions\ViewAction;
use PandaPanel\Tables\Columns\DateTimeColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Enums\SortDirection;
use PandaPanel\Tables\TableSchema;

final class ProgramMembersRelation extends MlmRelationManager
{
    protected static string $relationship = 'members';

    protected static function viewPermission(): string
    {
        return MlmPermission::MEMBERS_VIEW;
    }

    protected static function titleKey(): string
    {
        return 'members';
    }

    public static function table(TableSchema $table, Model $owner): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('member_code')->label(Display::field('member_code'))->searchable()->sortable(),
                TextColumn::make('external_type')->label(Display::field('external_type'))->placeholder(Display::none()),
                TextColumn::make('external_id')->label(Display::field('external_id'))->searchable()->placeholder(Display::none()),
                DateTimeColumn::make('joined_at')->label(Display::field('joined_at'))->sortable(),
            ])
            ->defaultSort('joined_at', SortDirection::Descending)
            ->recordActions([ViewAction::make(MemberResource::class)]);
    }
}
