<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Calculations;

use Illuminate\Database\Eloquent\Model;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Resources\Commissions\CommissionResource;
use PandaBear\Mlm\Panel\Support\Display;
use PandaBear\Mlm\Panel\Support\MlmRelationManager;
use PandaPanel\Actions\ViewAction;
use PandaPanel\Tables\Columns\BadgeColumn;
use PandaPanel\Tables\Columns\DateTimeColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

final class RunCommissionsRelation extends MlmRelationManager
{
    protected static string $relationship = 'commissions';

    /** @var list<string> */
    protected static array $with = ['member'];

    protected static function viewPermission(): string
    {
        return MlmPermission::COMMISSIONS_VIEW;
    }

    protected static function titleKey(): string
    {
        return 'commissions';
    }

    public static function table(TableSchema $table, Model $owner): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('member.member_code')->label(Display::field('member')),
                TextColumn::make('amount_millionths')
                    ->label(Display::field('amount'))
                    ->formatUsing(static fn (mixed $value, Commission $commission): ?string => Display::money($commission->amount_millionths, $commission->currency))
                    ->queryable(false),
                BadgeColumn::make('status')
                    ->label(Display::field('status'))
                    ->colors(Display::statusColors('commission'))
                    ->labels(Display::statusLabels('commission')),
                DateTimeColumn::make('earned_at')->label(Display::field('earned_at')),
                TextColumn::make('candidate_key')->label(Display::field('key'))->limit(40),
            ])
            ->recordActions([ViewAction::make(CommissionResource::class)]);
    }
}
