<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Payouts;

use Illuminate\Database\Eloquent\Model;
use PandaBear\Mlm\Models\PayoutRequest;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Support\Display;
use PandaBear\Mlm\Panel\Support\MlmNavigation;
use PandaBear\Mlm\Panel\Support\MlmResource;
use PandaPanel\Actions\ViewAction;
use PandaPanel\Forms\Enums\CalloutTone;
use PandaPanel\Forms\Layouts\Callout;
use PandaPanel\Infolists\Components\BadgeEntry;
use PandaPanel\Infolists\Components\DateTimeEntry;
use PandaPanel\Infolists\Components\TextEntry;
use PandaPanel\Infolists\InfolistSchema;
use PandaPanel\Infolists\Layouts\Section;
use PandaPanel\Tables\Columns\BadgeColumn;
use PandaPanel\Tables\Columns\DateTimeColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Enums\SortDirection;
use PandaPanel\Tables\Filters\SelectFilter;
use PandaPanel\Tables\TableSchema;

/**
 * Payout requests: read, and moved through their lifecycle by
 * `PayoutManager` only. Creation needs a destination workflow and belongs
 * to Phase 3.9B.
 */
final class PayoutRequestResource extends MlmResource
{
    protected static string $model = PayoutRequest::class;

    protected static ?string $slug = 'mlm-payout-requests';

    protected static ?string $recordTitleAttribute = 'idempotency_key';

    protected static ?string $navigationIcon = 'upload';

    protected static int $navigationSort = MlmNavigation::PAYOUT_REQUESTS;

    /** @var list<string> */
    protected static array $with = ['member', 'batchItem'];

    protected static function viewPermission(): string
    {
        return MlmPermission::PAYOUTS_VIEW;
    }

    protected static function translationKey(): string
    {
        return 'payout_request';
    }

    public static function table(TableSchema $table): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('member.member_code')->label(Display::field('member'))->searchable(),
                TextColumn::make('amount_millionths')
                    ->label(Display::field('amount'))
                    ->formatUsing(static fn (mixed $value, PayoutRequest $request): ?string => Display::money($request->amount_millionths, $request->currency))
                    ->queryable(false),
                BadgeColumn::make('status')
                    ->label(Display::field('status'))
                    ->colors(Display::statusColors('payout'))
                    ->labels(Display::statusLabels('payout')),
                TextColumn::make('destination_type')->label(Display::field('destination_type')),
                TextColumn::make('destination_reference')
                    ->label(Display::field('destination_reference'))
                    ->formatUsing(static fn (mixed $reference): ?string => Display::masked(is_string($reference) ? $reference : null))
                    ->queryable(false),
                DateTimeColumn::make('requested_at')->label(Display::field('requested_at'))->sortable(),
                DateTimeColumn::make('approved_at')->label(Display::field('approved_at'))->placeholder(Display::none()),
                DateTimeColumn::make('processing_at')->label(Display::field('processing_at'))->placeholder(Display::none())->visible(false),
                DateTimeColumn::make('settled_at')->label(Display::field('settled_at'))->placeholder(Display::none()),
                DateTimeColumn::make('failed_at')->label(Display::field('failed_at'))->placeholder(Display::none())->visible(false),
                DateTimeColumn::make('cancelled_at')->label(Display::field('cancelled_at'))->placeholder(Display::none())->visible(false),
                TextColumn::make('settlement_reference')->label(Display::field('settlement_reference'))->searchable()->placeholder(Display::none()),
                TextColumn::make('batchItem.payout_batch_id')->label(Display::field('batch_membership'))->placeholder(Display::none()),
            ])
            ->filters([
                SelectFilter::make('status')->label(Display::field('status'))->options(Display::statusOptions('payout')),
            ])
            ->callout(Callout::make(__('mlm::mlm.callouts.payouts'))->heading(__('mlm::mlm.callouts.payouts_heading'))->tone(CalloutTone::Warning))
            ->defaultSort('requested_at', SortDirection::Descending)
            ->emptyState(self::emptyHeading(), self::emptyDescription(), 'upload')
            ->recordActions([ViewAction::make(self::class), ...PayoutRequestActions::all()]);
    }

    public static function infolist(InfolistSchema $schema): InfolistSchema
    {
        $ledger = static fn (): bool => MlmPermission::allows(MlmPermission::LEDGER_VIEW);

        return $schema
            ->actions(PayoutRequestActions::all())
            ->schema([
                Section::make(Display::section('identity'))->columns(2)->schema([
                    TextEntry::make('member.member_code')->label(Display::field('member')),
                    TextEntry::make('wallet_id')->label(Display::field('wallet')),
                    TextEntry::make('amount')
                        ->label(Display::field('amount'))
                        ->formatUsing(static fn (mixed $value, PayoutRequest $request): ?string => Display::money($request->amount_millionths, $request->currency)),
                    BadgeEntry::make('status')
                        ->label(Display::field('status'))
                        ->formatUsing(static fn (mixed $status): ?string => Display::status('payout', $status))
                        ->colors(Display::statusColorsByLabel('payout')),
                    TextEntry::make('idempotency_key')->label(Display::field('idempotency_key')),
                    TextEntry::make('batchItem.payout_batch_id')->label(Display::field('batch_membership'))->placeholder(Display::none()),
                ]),
                Section::make(Display::section('destination'))
                    ->description(Display::section('destination_description'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('destination_type')->label(Display::field('destination_type')),
                        TextEntry::make('destination_reference')->label(Display::field('destination_reference')),
                        TextEntry::make('settlement_reference')->label(Display::field('settlement_reference'))->placeholder(Display::none()),
                        TextEntry::make('failure_reason')->label(Display::field('failure_reason'))->placeholder(Display::none()),
                    ]),
                Section::make(Display::section('lifecycle'))->columns(3)->schema([
                    DateTimeEntry::make('requested_at')->label(Display::field('requested_at')),
                    DateTimeEntry::make('approved_at')->label(Display::field('approved_at'))->placeholder(Display::none()),
                    DateTimeEntry::make('processing_at')->label(Display::field('processing_at'))->placeholder(Display::none()),
                    DateTimeEntry::make('settled_at')->label(Display::field('settled_at'))->placeholder(Display::none()),
                    DateTimeEntry::make('failed_at')->label(Display::field('failed_at'))->placeholder(Display::none()),
                    DateTimeEntry::make('cancelled_at')->label(Display::field('cancelled_at'))->placeholder(Display::none()),
                ]),
                Section::make(Display::section('ledger'))
                    ->description(Display::section('ledger_description'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('reservation_ledger_transaction_id')->label(Display::field('reservation'))->placeholder(Display::none())->visible($ledger),
                        TextEntry::make('refund_ledger_transaction_id')->label(Display::field('refund'))->placeholder(Display::none())->visible($ledger),
                        TextEntry::make('settlementAccount.key')->label(Display::field('ledger_account'))->visible($ledger),
                    ]),
            ]);
    }

    public static function pages(): array
    {
        return [
            'index' => ListPayoutRequests::class,
            'view' => ViewPayoutRequest::class,
        ];
    }

    public static function recordTitle(Model $record): string
    {
        /** @var PayoutRequest $record */
        return $record->member->member_code.' · '.Display::money($record->amount_millionths, $record->currency);
    }
}
