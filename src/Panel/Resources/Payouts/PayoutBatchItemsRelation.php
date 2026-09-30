<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Payouts;

use Illuminate\Database\Eloquent\Model;
use PandaBear\Mlm\Models\PayoutBatchItem;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Support\Display;
use PandaBear\Mlm\Panel\Support\MlmRelationManager;
use PandaPanel\Actions\Action;
use PandaPanel\Tables\Columns\BadgeColumn;
use PandaPanel\Tables\Columns\NumberColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

/**
 * The batch's requests in their order. Read-only: membership is fixed by
 * `PayoutBatchManager`, and each request is settled or failed on its own.
 */
final class PayoutBatchItemsRelation extends MlmRelationManager
{
    protected static string $relationship = 'items';

    /** @var list<string> */
    protected static array $with = ['request.member'];

    protected static function viewPermission(): string
    {
        return MlmPermission::PAYOUTS_VIEW;
    }

    protected static function titleKey(): string
    {
        return 'items';
    }

    public static function table(TableSchema $table, Model $owner): TableSchema
    {
        return $table
            ->columns([
                NumberColumn::make('position')->label(Display::field('position')),
                TextColumn::make('request.member.member_code')->label(Display::field('member')),
                TextColumn::make('amount')
                    ->label(Display::field('amount'))
                    ->formatUsing(static fn (mixed $value, PayoutBatchItem $item): ?string => Display::money($item->request->amount_millionths, $item->request->currency))
                    ->queryable(false),
                BadgeColumn::make('request.status')
                    ->label(Display::field('status'))
                    ->colors(Display::statusColors('payout'))
                    ->labels(Display::statusLabels('payout')),
                TextColumn::make('request.settlement_reference')->label(Display::field('settlement_reference'))->placeholder(Display::none()),
                TextColumn::make('request.failure_reason')->label(Display::field('failure_reason'))->placeholder(Display::none()),
            ])
            ->recordActions([
                Action::make('open-request')
                    ->label(__('mlm::mlm.resources.payout_request.label'))
                    ->icon('eye')
                    ->url(static fn (PayoutBatchItem $item): string => PayoutRequestResource::url('view', $item->payout_request_id))
                    ->authorize(static fn (?Model $item = null): bool => PayoutRequestResource::canViewAny()),
            ]);
    }
}
