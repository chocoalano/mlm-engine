<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Periods;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\CommissionPeriodRun;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Resources\Calculations\CalculationRunResource;
use PandaBear\Mlm\Panel\Support\Display;
use PandaBear\Mlm\Panel\Support\MlmRelationManager;
use PandaPanel\Actions\Action;
use PandaPanel\Tables\Columns\DateTimeColumn;
use PandaPanel\Tables\Columns\NumberColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

/**
 * The period's calculation runs, one per commission component, in order.
 */
final class PeriodRunsRelation extends MlmRelationManager
{
    protected static string $relationship = 'runs';

    /** @var list<string> */
    protected static array $with = ['component', 'run'];

    protected static function viewPermission(): string
    {
        return MlmPermission::CALCULATIONS_VIEW;
    }

    protected static function titleKey(): string
    {
        return 'runs';
    }

    /**
     * Each run's commission count as a subquery, not a count per row.
     */
    public static function relationForTable(Model $owner): Relation
    {
        $relation = parent::relationForTable($owner);

        $relation->getQuery()
            ->select('mlm_commission_period_runs.*')
            ->addSelect(['commissions_count' => Commission::query()
                ->selectRaw('count(*)')
                ->whereColumn('mlm_commissions.calculation_run_id', 'mlm_commission_period_runs.calculation_run_id')]);

        return $relation;
    }

    public static function table(TableSchema $table, Model $owner): TableSchema
    {
        return $table
            ->columns([
                NumberColumn::make('position')->label(Display::field('position')),
                TextColumn::make('component.key')->label(Display::field('component')),
                TextColumn::make('run.strategy')->label(Display::field('strategy'))->placeholder(Display::none()),
                TextColumn::make('calculation_run_id')->label(Display::field('run'))->placeholder(Display::none()),
                DateTimeColumn::make('run.from_at')->label(Display::field('from_at'))->placeholder(Display::none()),
                DateTimeColumn::make('run.until_at')->label(Display::field('until_at'))->placeholder(Display::none()),
                NumberColumn::make('commissions_count')->label(Display::field('commissions_count'))->queryable(false),
            ])
            ->recordActions([
                Action::make('open-run')
                    ->label(Display::field('run'))
                    ->icon('eye')
                    ->url(static fn (CommissionPeriodRun $record): string => CalculationRunResource::url('view', $record->calculation_run_id))
                    ->visible(static fn (?Model $record = null): bool => $record instanceof CommissionPeriodRun && $record->calculation_run_id !== null)
                    ->authorize(static fn (?Model $record = null): bool => CalculationRunResource::canViewAny()),
            ]);
    }
}
