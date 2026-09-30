<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Payouts;

use PandaBear\Mlm\Models\PayoutBatch;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Support\Display;
use PandaBear\Mlm\Panel\Support\MlmNavigation;
use PandaBear\Mlm\Panel\Support\MlmResource;
use PandaBear\Mlm\Panel\Support\ProgramFilter;
use PandaBear\Mlm\Payout\PayoutBatchTotals;
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
use PandaPanel\Tables\Columns\NumberColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Enums\SortDirection;
use PandaPanel\Tables\Filters\SelectFilter;
use PandaPanel\Tables\TableSchema;
use WeakMap;

/**
 * Payout batches: read, and moved through their lifecycle by
 * `PayoutBatchManager` only.
 */
final class PayoutBatchResource extends MlmResource
{
    protected static string $model = PayoutBatch::class;

    protected static ?string $slug = 'mlm-payout-batches';

    protected static ?string $recordTitleAttribute = 'idempotency_key';

    protected static ?string $navigationIcon = 'link';

    protected static int $navigationSort = MlmNavigation::PAYOUT_BATCHES;

    /** @var list<string> */
    protected static array $with = ['program'];

    /** @var WeakMap<PayoutBatch, PayoutBatchTotals>|null */
    private static ?WeakMap $totals = null;

    protected static function viewPermission(): string
    {
        return MlmPermission::PAYOUTS_VIEW;
    }

    protected static function translationKey(): string
    {
        return 'payout_batch';
    }

    public static function table(TableSchema $table): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('program.name')->label(Display::field('program')),
                TextColumn::make('idempotency_key')->label(Display::field('idempotency_key'))->searchable()->limit(40),
                TextColumn::make('currency')->label(Display::field('currency')),
                BadgeColumn::make('status')
                    ->label(Display::field('status'))
                    ->colors(Display::statusColors('batch'))
                    ->labels(Display::statusLabels('batch')),
                NumberColumn::make('items_count')->label(Display::field('items_count'))->counts('items')->queryable(false),
                DateTimeColumn::make('created_at')->label(Display::field('created_at'))->sortable(),
                DateTimeColumn::make('sealed_at')->label(Display::field('sealed_at'))->placeholder(Display::none()),
                DateTimeColumn::make('processing_at')->label(Display::field('processing_at'))->placeholder(Display::none()),
                DateTimeColumn::make('completed_at')->label(Display::field('completed_at'))->placeholder(Display::none()),
                DateTimeColumn::make('cancelled_at')->label(Display::field('cancelled_at'))->placeholder(Display::none())->visible(false),
            ])
            ->filters([
                ProgramFilter::make(),
                SelectFilter::make('status')->label(Display::field('status'))->options(Display::statusOptions('batch')),
            ])
            ->headerActions([PayoutBatchActions::creation()])
            ->emptyStateActions([PayoutBatchActions::creation()])
            ->callout(Callout::make(__('mlm::mlm.callouts.batches'))->heading(__('mlm::mlm.callouts.batches_heading'))->tone(CalloutTone::Info))
            ->defaultSort('created_at', SortDirection::Descending)
            ->emptyState(self::emptyHeading(), self::emptyDescription(), 'link')
            ->recordActions([ViewAction::make(self::class), ...PayoutBatchActions::all()]);
    }

    public static function infolist(InfolistSchema $schema): InfolistSchema
    {
        $money = static fn (string $total): \Closure => static fn (mixed $value, PayoutBatch $batch): ?string => Display::money(self::totals($batch)->{$total}, $batch->currency);

        return $schema
            ->actions([PayoutBatchActions::addRequest(), ...PayoutBatchActions::all()])
            ->schema([
                Section::make(Display::section('identity'))->columns(2)->schema([
                    TextEntry::make('program.name')->label(Display::field('program')),
                    TextEntry::make('currency')->label(Display::field('currency')),
                    BadgeEntry::make('status')
                        ->label(Display::field('status'))
                        ->formatUsing(static fn (mixed $status): ?string => Display::status('batch', $status))
                        ->colors(Display::statusColorsByLabel('batch')),
                    TextEntry::make('idempotency_key')->label(Display::field('idempotency_key')),
                    TextEntry::make('id')->label(Display::field('id')),
                ]),
                Section::make(Display::section('totals'))->columns(3)->schema([
                    TextEntry::make('items_count')
                        ->label(Display::field('items_count'))
                        ->formatUsing(static fn (mixed $value, PayoutBatch $batch): string => (string) self::totals($batch)->count),
                    TextEntry::make('requested_total')->label(Display::field('requested_total'))->formatUsing($money('requested')),
                    TextEntry::make('reserved_total')->label(Display::field('reserved_total'))->formatUsing($money('reserved')),
                    TextEntry::make('settled_total')
                        ->label(Display::field('settled_total'))
                        ->formatUsing(static fn (mixed $value, PayoutBatch $batch): string => Display::money(self::totals($batch)->settled, $batch->currency).' ('.self::totals($batch)->settledCount.')'),
                    TextEntry::make('failed_total')
                        ->label(Display::field('failed_total'))
                        ->formatUsing(static fn (mixed $value, PayoutBatch $batch): string => Display::money(self::totals($batch)->failed, $batch->currency).' ('.self::totals($batch)->failedCount.')'),
                ]),
                Section::make(Display::section('lifecycle'))->columns(3)->schema([
                    DateTimeEntry::make('created_at')->label(Display::field('created_at')),
                    DateTimeEntry::make('sealed_at')->label(Display::field('sealed_at'))->placeholder(Display::none()),
                    DateTimeEntry::make('processing_at')->label(Display::field('processing_at'))->placeholder(Display::none()),
                    DateTimeEntry::make('completed_at')->label(Display::field('completed_at'))->placeholder(Display::none()),
                    DateTimeEntry::make('cancelled_at')->label(Display::field('cancelled_at'))->placeholder(Display::none()),
                ]),
            ]);
    }

    public static function pages(): array
    {
        return [
            'index' => ListPayoutBatches::class,
            'view' => ViewPayoutBatch::class,
        ];
    }

    public static function relationManagers(): array
    {
        return [PayoutBatchItemsRelation::class];
    }

    /**
     * Read once per batch per request, however many entries show them.
     */
    private static function totals(PayoutBatch $batch): PayoutBatchTotals
    {
        self::$totals ??= new WeakMap;

        return self::$totals[$batch] ??= PayoutBatchTotals::of($batch);
    }
}
