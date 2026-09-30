<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Commissions;

use Illuminate\Database\Eloquent\Model;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Support\Display;
use PandaBear\Mlm\Panel\Support\MlmNavigation;
use PandaBear\Mlm\Panel\Support\MlmResource;
use PandaBear\Mlm\Panel\Support\ProgramFilter;
use PandaPanel\Actions\ViewAction;
use PandaPanel\Forms\Enums\CodeLanguage;
use PandaPanel\Infolists\Components\BadgeEntry;
use PandaPanel\Infolists\Components\CodeEntry;
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
 * Commissions, read-only. Their lifecycle is moved by the period services
 * and the commission lifecycle, posting and adjustment services — never by
 * a status edit here.
 */
final class CommissionResource extends MlmResource
{
    protected static string $model = Commission::class;

    protected static ?string $slug = 'mlm-commissions';

    protected static ?string $navigationIcon = 'check';

    protected static int $navigationSort = MlmNavigation::COMMISSIONS;

    /** @var list<string> */
    protected static array $with = ['member', 'program', 'run.component'];

    protected static function viewPermission(): string
    {
        return MlmPermission::COMMISSIONS_VIEW;
    }

    protected static function translationKey(): string
    {
        return 'commission';
    }

    public static function recordTitle(Model $record): string
    {
        /** @var Commission $record */
        return $record->member->member_code.' · '.Display::money($record->amount_millionths, $record->currency);
    }

    public static function table(TableSchema $table): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('member.member_code')->label(Display::field('member'))->searchable(),
                TextColumn::make('program.name')->label(Display::field('program')),
                TextColumn::make('run.component.key')->label(Display::field('component')),
                TextColumn::make('run.strategy')->label(Display::field('strategy')),
                TextColumn::make('amount_millionths')
                    ->label(Display::field('amount'))
                    ->formatUsing(static fn (mixed $value, Commission $commission): ?string => Display::money($commission->amount_millionths, $commission->currency))
                    ->queryable(false),
                BadgeColumn::make('status')
                    ->label(Display::field('status'))
                    ->colors(Display::statusColors('commission'))
                    ->labels(Display::statusLabels('commission'))
                    ->tooltip(static fn (Commission $commission): ?string => Display::statusHelp('commission', $commission->status)),
                DateTimeColumn::make('earned_at')->label(Display::field('earned_at'))->sortable(),
                TextColumn::make('source_id')
                    ->label(Display::field('source'))
                    ->formatUsing(static fn (mixed $value, Commission $commission): ?string => self::source($commission))
                    ->searchable()
                    ->placeholder(Display::none()),
                TextColumn::make('posted_amount_millionths')
                    ->label(Display::field('posted_amount'))
                    ->formatUsing(static fn (mixed $value, Commission $commission): ?string => Display::money($commission->posted_amount_millionths, $commission->currency))
                    ->placeholder(Display::none())
                    ->queryable(false),
                DateTimeColumn::make('created_at')->label(Display::field('created_at'))->sortable()->visible(false),
            ])
            ->filters([
                ProgramFilter::make(),
                SelectFilter::make('status')->label(Display::field('status'))->options(Display::statusOptions('commission')),
            ])
            ->defaultSort('earned_at', SortDirection::Descending)
            ->emptyState(self::emptyHeading(), self::emptyDescription(), 'check')
            ->recordActions([ViewAction::make(self::class), ...CommissionActions::all()]);
    }

    public static function infolist(InfolistSchema $schema): InfolistSchema
    {
        $ledger = static fn (): bool => MlmPermission::allows(MlmPermission::LEDGER_VIEW);

        return $schema->actions(CommissionActions::all())->schema([
            Section::make(Display::section('identity'))->columns(2)->schema([
                TextEntry::make('member.member_code')->label(Display::field('member')),
                TextEntry::make('program.name')->label(Display::field('program')),
                TextEntry::make('run.component.key')->label(Display::field('component')),
                TextEntry::make('run.strategy')->label(Display::field('strategy')),
                TextEntry::make('calculation_run_id')->label(Display::field('run')),
                TextEntry::make('source')
                    ->label(Display::field('source'))
                    ->formatUsing(static fn (mixed $value, Commission $commission): ?string => self::source($commission))
                    ->placeholder(Display::none()),
                DateTimeEntry::make('earned_at')->label(Display::field('earned_at')),
                TextEntry::make('id')->label(Display::field('id')),
            ]),
            Section::make(Display::section('money'))->columns(2)->schema([
                TextEntry::make('amount')
                    ->label(Display::field('amount'))
                    ->formatUsing(static fn (mixed $value, Commission $commission): ?string => Display::money($commission->amount_millionths, $commission->currency)),
                TextEntry::make('posted_amount')
                    ->label(Display::field('posted_amount'))
                    ->formatUsing(static fn (mixed $value, Commission $commission): ?string => Display::money($commission->posted_amount_millionths, $commission->currency))
                    ->placeholder(Display::none()),
                BadgeEntry::make('status')
                    ->label(Display::field('status'))
                    ->formatUsing(static fn (mixed $status): ?string => Display::status('commission', $status))
                    ->colors(Display::statusColorsByLabel('commission')),
                TextEntry::make('status_help')
                    ->label(Display::section('status'))
                    ->formatUsing(static fn (mixed $value, Commission $commission): ?string => Display::statusHelp('commission', $commission->status)),
            ]),
            Section::make(Display::section('lifecycle'))->columns(4)->schema([
                DateTimeEntry::make('pending_at')->label(Display::field('pending_at'))->placeholder(Display::none()),
                DateTimeEntry::make('approved_at')->label(Display::field('approved_at'))->placeholder(Display::none()),
                DateTimeEntry::make('held_at')->label(Display::field('held_at'))->placeholder(Display::none()),
                DateTimeEntry::make('available_at')->label(Display::field('available_at'))->placeholder(Display::none()),
                DateTimeEntry::make('posted_at')->label(Display::field('posted_at'))->placeholder(Display::none()),
                DateTimeEntry::make('cancelled_at')->label(Display::field('cancelled_at'))->placeholder(Display::none()),
                DateTimeEntry::make('reversed_at')->label(Display::field('reversed_at'))->placeholder(Display::none()),
            ]),
            Section::make(Display::section('ledger'))
                ->description(Display::section('ledger_description'))
                ->columns(2)
                ->schema([
                    TextEntry::make('ledger_transaction_id')->label(Display::field('ledger_transaction'))->placeholder(Display::none())->visible($ledger),
                    TextEntry::make('ledgerTransaction.type')->label(Display::field('type'))->placeholder(Display::none())->visible($ledger),
                    DateTimeEntry::make('ledgerTransaction.occurred_at')->label(Display::field('occurred_at'))->placeholder(Display::none())->visible($ledger),
                    TextEntry::make('reversal_ledger_transaction_id')->label(Display::field('reversal_transaction'))->placeholder(Display::none())->visible($ledger),
                ]),
            Section::make(Display::section('trace'))
                ->description(Display::section('trace_description'))
                ->schema([
                    CodeEntry::make('trace')->label(Display::field('trace'))->language(CodeLanguage::Json)->columnSpanFull(),
                ]),
        ]);
    }

    public static function pages(): array
    {
        return [
            'index' => ListCommissions::class,
            'view' => ViewCommission::class,
        ];
    }

    public static function relationManagers(): array
    {
        return [CommissionAdjustmentsRelation::class];
    }

    private static function source(Commission $commission): ?string
    {
        return $commission->source_type === null ? null : "{$commission->source_type}:{$commission->source_id}";
    }
}
