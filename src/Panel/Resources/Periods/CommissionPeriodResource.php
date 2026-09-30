<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Periods;

use Illuminate\Database\Eloquent\Model;
use PandaBear\Mlm\Models\CommissionPeriod;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Support\Display;
use PandaBear\Mlm\Panel\Support\MlmNavigation;
use PandaBear\Mlm\Panel\Support\MlmResource;
use PandaBear\Mlm\Period\CommissionPeriodTotals;
use PandaPanel\Actions\ViewAction;
use PandaPanel\Forms\Enums\CalloutTone;
use PandaPanel\Forms\Layouts\Callout;
use PandaPanel\Infolists\Components\BadgeEntry;
use PandaPanel\Infolists\Components\DateTimeEntry;
use PandaPanel\Infolists\Components\KeyValueEntry;
use PandaPanel\Infolists\Components\TextEntry;
use PandaPanel\Infolists\InfolistSchema;
use PandaPanel\Infolists\Layouts\Section;
use PandaPanel\Tables\Columns\BadgeColumn;
use PandaPanel\Tables\Columns\DateTimeColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Enums\SortDirection;
use PandaPanel\Tables\Filters\SelectFilter;
use PandaPanel\Tables\TableSchema;
use WeakMap;

/**
 * Commission periods: the operator's calculation, review and release
 * boundary. Calculate, finalize and release run through the period services.
 */
final class CommissionPeriodResource extends MlmResource
{
    protected static string $model = CommissionPeriod::class;

    protected static ?string $slug = 'mlm-commission-periods';

    protected static ?string $navigationIcon = 'rotate-ccw';

    protected static int $navigationSort = MlmNavigation::PERIODS;

    /** @var list<string> */
    protected static array $with = ['program', 'planVersion.plan', 'sourceAccount'];

    /** @var WeakMap<CommissionPeriod, CommissionPeriodTotals>|null */
    private static ?WeakMap $totals = null;

    protected static function viewPermission(): string
    {
        return MlmPermission::PERIODS_VIEW;
    }

    protected static function translationKey(): string
    {
        return 'period';
    }

    public static function recordTitle(Model $record): string
    {
        /** @var CommissionPeriod $record */
        return Display::moment($record->from_at).' → '.Display::moment($record->until_at);
    }

    public static function table(TableSchema $table): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('program.name')->label(Display::field('program')),
                TextColumn::make('plan_version')
                    ->label(Display::field('plan_version'))
                    ->formatUsing(static fn (mixed $value, CommissionPeriod $period): string => self::planVersion($period))
                    ->queryable(false),
                DateTimeColumn::make('from_at')->label(Display::field('from_at'))->sortable(),
                DateTimeColumn::make('until_at')->label(Display::field('until_at'))->sortable(),
                BadgeColumn::make('status')
                    ->label(Display::field('status'))
                    ->formatUsing(static fn (mixed $status, CommissionPeriod $period): string => Display::periodState($period))
                    ->colors(Display::statusColors('period'))
                    ->labels(Display::statusLabels('period'))
                    ->tooltip(static fn (CommissionPeriod $period): ?string => Display::statusHelp('period', Display::periodState($period))),
                DateTimeColumn::make('release_at')->label(Display::field('release_at'))->sortable(),
                DateTimeColumn::make('input_closed_at')->label(Display::field('input_closed_at'))->placeholder(Display::none()),
                DateTimeColumn::make('calculated_at')->label(Display::field('calculated_at'))->placeholder(Display::none()),
                DateTimeColumn::make('finalized_at')->label(Display::field('finalized_at'))->placeholder(Display::none()),
                DateTimeColumn::make('released_at')->label(Display::field('released_at'))->placeholder(Display::none()),
                TextColumn::make('sourceAccount.currency')->label(Display::field('currency')),
            ])
            ->filters([
                SelectFilter::make('status')->label(Display::field('status'))->options(Display::statusOptions('period')),
            ])
            ->callout(Callout::make(__('mlm::mlm.callouts.periods'))->heading(__('mlm::mlm.callouts.periods_heading'))->tone(CalloutTone::Info))
            ->defaultSort('from_at', SortDirection::Descending)
            ->emptyState(self::emptyHeading(), self::emptyDescription(), 'rotate-ccw')
            ->recordActions([ViewAction::make(self::class), ...PeriodActions::all()]);
    }

    public static function infolist(InfolistSchema $schema): InfolistSchema
    {
        return $schema
            ->actions(PeriodActions::all())
            ->schema([
                Section::make(Display::section('status'))->columns(2)->schema([
                    BadgeEntry::make('status')
                        ->label(Display::field('status'))
                        ->formatUsing(static fn (mixed $status, CommissionPeriod $period): ?string => Display::status('period', Display::periodState($period)))
                        ->colors(Display::statusColorsByLabel('period')),
                    TextEntry::make('status_help')
                        ->label(Display::section('status'))
                        ->formatUsing(static fn (mixed $value, CommissionPeriod $period): ?string => Display::statusHelp('period', Display::periodState($period))),
                ]),
                Section::make(Display::section('identity'))->columns(2)->schema([
                    TextEntry::make('program.name')->label(Display::field('program')),
                    TextEntry::make('plan_version')
                        ->label(Display::field('plan_version'))
                        ->formatUsing(static fn (mixed $value, CommissionPeriod $period): string => self::planVersion($period)),
                    TextEntry::make('range')
                        ->label(Display::field('range'))
                        ->formatUsing(static fn (mixed $value, CommissionPeriod $period): string => self::recordTitle($period)),
                    DateTimeEntry::make('release_at')->label(Display::field('release_at')),
                    TextEntry::make('sourceAccount.key')->label(Display::field('source_account')),
                    TextEntry::make('sourceAccount.currency')->label(Display::field('currency')),
                    TextEntry::make('idempotency_key')->label(Display::field('idempotency_key')),
                    TextEntry::make('id')->label(Display::field('id')),
                ]),
                Section::make(Display::section('lifecycle'))->columns(4)->schema([
                    DateTimeEntry::make('input_closed_at')->label(Display::field('input_closed_at'))->placeholder(Display::none()),
                    DateTimeEntry::make('calculated_at')->label(Display::field('calculated_at'))->placeholder(Display::none()),
                    DateTimeEntry::make('finalized_at')->label(Display::field('finalized_at'))->placeholder(Display::none()),
                    DateTimeEntry::make('released_at')->label(Display::field('released_at'))->placeholder(Display::none()),
                ]),
                Section::make(Display::section('totals'))->columns(2)->schema([
                    TextEntry::make('calculated_total')
                        ->label(Display::field('calculated_total'))
                        ->formatUsing(static fn (mixed $value, CommissionPeriod $period): ?string => Display::money(self::totals($period)->calculated, $period->sourceAccount?->currency)),
                    TextEntry::make('net_total')
                        ->label(Display::field('net_total'))
                        ->formatUsing(static fn (mixed $value, CommissionPeriod $period): ?string => Display::money(self::totals($period)->net, $period->sourceAccount?->currency)),
                    TextEntry::make('posted_total')
                        ->label(Display::field('posted_total'))
                        ->formatUsing(static fn (mixed $value, CommissionPeriod $period): ?string => Display::money(self::totals($period)->posted, $period->sourceAccount?->currency)),
                    KeyValueEntry::make('counts')
                        ->label(Display::field('counts'))
                        ->formatUsing(static function (mixed $value, CommissionPeriod $period): array {
                            $counts = [];

                            foreach (self::totals($period)->counts as $status => $count) {
                                $counts[(string) Display::status('commission', (string) $status)] = $count;
                            }

                            return $counts;
                        }),
                ]),
            ]);
    }

    public static function pages(): array
    {
        return [
            'index' => ListCommissionPeriods::class,
            'view' => ViewCommissionPeriod::class,
        ];
    }

    public static function relationManagers(): array
    {
        return [PeriodRunsRelation::class];
    }

    private static function planVersion(CommissionPeriod $period): string
    {
        return $period->planVersion->plan->name.' '.__('mlm::mlm.values.version', ['version' => $period->planVersion->version]);
    }

    /**
     * Read once per period per request, however many entries show them.
     */
    private static function totals(CommissionPeriod $period): CommissionPeriodTotals
    {
        self::$totals ??= new WeakMap;

        return self::$totals[$period] ??= CommissionPeriodTotals::of($period);
    }
}
