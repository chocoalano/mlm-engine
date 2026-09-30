<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Commissions;

use Illuminate\Database\Eloquent\Model;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\CommissionAdjustment;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Support\Display;
use PandaBear\Mlm\Panel\Support\MlmRelationManager;
use PandaPanel\Tables\Columns\BadgeColumn;
use PandaPanel\Tables\Columns\DateTimeColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

/**
 * What happened to a commission after it was calculated: each adjustment,
 * its signed amount, and the outcome the adjustment engine recorded.
 */
final class CommissionAdjustmentsRelation extends MlmRelationManager
{
    protected static string $relationship = 'adjustments';

    protected static function viewPermission(): string
    {
        return MlmPermission::COMMISSIONS_VIEW;
    }

    protected static function titleKey(): string
    {
        return 'adjustments';
    }

    public static function table(TableSchema $table, Model $owner): TableSchema
    {
        /** @var Commission $owner */
        return $table->columns([
            TextColumn::make('type')->label(Display::field('type')),
            TextColumn::make('source_id')
                ->label(Display::field('source'))
                ->formatUsing(static fn (mixed $value, CommissionAdjustment $adjustment): string => "{$adjustment->source_type}:{$adjustment->source_id}"),
            TextColumn::make('amount_millionths')
                ->label(Display::field('signed_amount'))
                ->formatUsing(static fn (mixed $value, CommissionAdjustment $adjustment): ?string => Display::money($adjustment->amount_millionths, $owner->currency))
                ->queryable(false),
            BadgeColumn::make('outcome')
                ->label(Display::field('outcome'))
                ->colors(Display::statusColors('adjustment'))
                ->labels(Display::statusLabels('adjustment')),
            DateTimeColumn::make('occurred_at')->label(Display::field('occurred_at')),
            TextColumn::make('ledger_transaction_id')->label(Display::field('ledger_transaction'))->placeholder(Display::none()),
        ]);
    }
}
