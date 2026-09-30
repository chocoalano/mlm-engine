<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Members;

use Illuminate\Database\Eloquent\Model;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Resources\Wallets\WalletResource;
use PandaBear\Mlm\Panel\Support\Display;
use PandaBear\Mlm\Panel\Support\MlmRelationManager;
use PandaBear\Mlm\Panel\Support\WalletBalanceColumn;
use PandaPanel\Actions\ViewAction;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Enums\SortDirection;
use PandaPanel\Tables\TableSchema;

final class MemberWalletsRelation extends MlmRelationManager
{
    protected static string $relationship = 'wallets';

    protected static function viewPermission(): string
    {
        return MlmPermission::WALLETS_VIEW;
    }

    protected static function titleKey(): string
    {
        return 'wallets';
    }

    public static function table(TableSchema $table, Model $owner): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('currency')->label(Display::field('currency'))->sortable(),
                WalletBalanceColumn::make('balance')->label(Display::field('balance')),
            ])
            ->defaultSort('currency', SortDirection::Ascending)
            ->recordActions([ViewAction::make(WalletResource::class)]);
    }
}
