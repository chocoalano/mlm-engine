<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Calculations;

use Illuminate\Database\Eloquent\Model;
use PandaBear\Mlm\Models\CalculationRun;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Resources\Periods\CommissionPeriodResource;
use PandaBear\Mlm\Panel\Support\Display;
use PandaBear\Mlm\Panel\Support\MlmNavigation;
use PandaBear\Mlm\Panel\Support\MlmResource;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\ViewAction;
use PandaPanel\Infolists\Components\DateTimeEntry;
use PandaPanel\Infolists\Components\TextEntry;
use PandaPanel\Infolists\InfolistSchema;
use PandaPanel\Infolists\Layouts\Section;
use PandaPanel\Tables\Columns\DateTimeColumn;
use PandaPanel\Tables\Columns\NumberColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Enums\SortDirection;
use PandaPanel\Tables\TableSchema;

/**
 * Calculation runs, read-only, with what each belongs to: a commission
 * period, a hybrid calculation batch, or neither.
 */
final class CalculationRunResource extends MlmResource
{
    protected static string $model = CalculationRun::class;

    protected static ?string $slug = 'mlm-calculation-runs';

    protected static ?string $recordTitleAttribute = 'idempotency_key';

    protected static ?string $navigationIcon = 'search';

    protected static int $navigationSort = MlmNavigation::RUNS;

    /** @var list<string> */
    protected static array $with = ['program', 'component', 'periodRun', 'batchItem'];

    protected static function viewPermission(): string
    {
        return MlmPermission::CALCULATIONS_VIEW;
    }

    protected static function translationKey(): string
    {
        return 'run';
    }

    public static function table(TableSchema $table): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('program.name')->label(Display::field('program')),
                TextColumn::make('component.key')->label(Display::field('component')),
                TextColumn::make('strategy')->label(Display::field('strategy'))->sortable(),
                DateTimeColumn::make('from_at')->label(Display::field('from_at'))->sortable(),
                DateTimeColumn::make('until_at')->label(Display::field('until_at'))->sortable(),
                TextColumn::make('currency')->label(Display::field('currency')),
                TextColumn::make('provenance')
                    ->label(Display::field('provenance'))
                    ->formatUsing(static fn (mixed $value, CalculationRun $run): string => self::provenance($run))
                    ->queryable(false),
                NumberColumn::make('commissions_count')->label(Display::field('commissions_count'))->counts('commissions')->queryable(false),
                TextColumn::make('idempotency_key')->label(Display::field('idempotency_key'))->searchable()->limit(40),
                DateTimeColumn::make('created_at')->label(Display::field('created_at'))->sortable(),
            ])
            ->defaultSort('created_at', SortDirection::Descending)
            ->emptyState(self::emptyHeading(), self::emptyDescription(), 'search')
            ->recordActions([ViewAction::make(self::class)]);
    }

    public static function infolist(InfolistSchema $schema): InfolistSchema
    {
        return $schema->schema([
            Section::make(Display::section('identity'))->columns(2)->schema([
                TextEntry::make('program.name')->label(Display::field('program')),
                TextEntry::make('component.key')->label(Display::field('component')),
                TextEntry::make('component.name')->label(Display::field('name')),
                TextEntry::make('strategy')->label(Display::field('strategy')),
                TextEntry::make('currency')->label(Display::field('currency')),
                TextEntry::make('range')
                    ->label(Display::field('range'))
                    ->formatUsing(static fn (mixed $value, CalculationRun $run): string => Display::moment($run->from_at).' → '.Display::moment($run->until_at)),
                TextEntry::make('idempotency_key')->label(Display::field('idempotency_key')),
                DateTimeEntry::make('created_at')->label(Display::field('created_at')),
                TextEntry::make('commissions_count')
                    ->label(Display::field('commissions_count'))
                    ->formatUsing(static fn (mixed $value, CalculationRun $run): string => (string) $run->commissions()->count()),
                TextEntry::make('id')->label(Display::field('id')),
            ]),
            Section::make(Display::section('provenance'))->columns(2)->schema([
                TextEntry::make('provenance')
                    ->label(Display::field('provenance'))
                    ->formatUsing(static fn (mixed $value, CalculationRun $run): string => self::provenance($run)),
                TextEntry::make('periodRun.commission_period_id')
                    ->label(Display::field('period'))
                    ->placeholder(Display::none())
                    ->action(Action::make('open-period')
                        ->label(Display::field('period'))
                        ->icon('eye')
                        ->url(static fn (CalculationRun $run): string => CommissionPeriodResource::url('view', $run->periodRun?->commission_period_id))
                        ->visible(static fn (?Model $run = null): bool => $run instanceof CalculationRun && $run->periodRun !== null)
                        ->authorize(static fn (?Model $run = null): bool => CommissionPeriodResource::canViewAny())),
                TextEntry::make('batchItem.calculation_batch_id')->label(Display::field('batch'))->placeholder(Display::none()),
            ]),
        ]);
    }

    public static function pages(): array
    {
        return [
            'index' => ListCalculationRuns::class,
            'view' => ViewCalculationRun::class,
        ];
    }

    public static function relationManagers(): array
    {
        return [RunCommissionsRelation::class];
    }

    /**
     * From the relational links the run was recorded with — never guessed
     * from its key.
     */
    private static function provenance(CalculationRun $run): string
    {
        return match (true) {
            $run->periodRun !== null => __('mlm::mlm.values.provenance_period'),
            $run->batchItem !== null => __('mlm::mlm.values.provenance_batch'),
            default => __('mlm::mlm.values.standalone'),
        };
    }
}
