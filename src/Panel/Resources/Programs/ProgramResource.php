<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Programs;

use Illuminate\Database\Eloquent\Builder;
use PandaBear\Mlm\Models\CommissionPeriod;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Support\Display;
use PandaBear\Mlm\Panel\Support\MlmNavigation;
use PandaBear\Mlm\Panel\Support\MlmResource;
use PandaBear\Mlm\Planning\PlanVersionStatus;
use PandaPanel\Actions\ViewAction;
use PandaPanel\Infolists\Components\DateTimeEntry;
use PandaPanel\Infolists\Components\TextEntry;
use PandaPanel\Infolists\InfolistSchema;
use PandaPanel\Infolists\Layouts\Section;
use PandaPanel\Tables\Columns\NumberColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Enums\SortDirection;
use PandaPanel\Tables\TableSchema;

/**
 * Programs, the business boundary: read-only. Programs are created by the
 * application.
 */
final class ProgramResource extends MlmResource
{
    protected static string $model = Program::class;

    protected static ?string $slug = 'mlm-programs';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationIcon = 'settings';

    protected static int $navigationSort = MlmNavigation::PROGRAMS;

    protected static function viewPermission(): string
    {
        return MlmPermission::PROGRAMS_VIEW;
    }

    protected static function translationKey(): string
    {
        return 'program';
    }

    /**
     * Each program with the facts its list shows, in the one query: plans
     * whose version is active, and the latest period.
     *
     * @return Builder<Program>
     */
    public static function query(): Builder
    {
        $latest = static fn (string $column): Builder => CommissionPeriod::query()
            ->select($column)
            ->whereColumn('mlm_commission_periods.program_id', 'mlm_programs.id')
            ->orderByDesc('from_at')
            ->limit(1);

        return parent::query()
            ->withCount(['plans as active_plans_count' => static fn (Builder $plans): Builder => $plans->whereHas(
                'versions',
                static fn (Builder $versions): Builder => $versions->where('status', PlanVersionStatus::Active->value),
            )])
            ->addSelect([
                'latest_period_status' => $latest('status'),
                'latest_period_from' => $latest('from_at'),
                'latest_period_until' => $latest('until_at'),
            ]);
    }

    public static function table(TableSchema $table): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('code')->label(Display::field('code'))->searchable()->sortable(),
                TextColumn::make('name')->label(Display::field('name'))->searchable()->sortable(),
                NumberColumn::make('members_count')->label(Display::field('members_count'))->counts('members')->queryable(false),
                NumberColumn::make('wallets_count')->label(Display::field('wallets_count'))->counts('wallets')->queryable(false),
                NumberColumn::make('active_plans_count')->label(Display::field('active_plans_count'))->queryable(false),
                TextColumn::make('latest_period_status')
                    ->label(Display::field('latest_period'))
                    ->formatUsing(static fn (mixed $status, Program $program): ?string => self::latestPeriod($program))
                    ->placeholder(Display::none())
                    ->queryable(false),
            ])
            ->defaultSort('code', SortDirection::Ascending)
            ->emptyState(self::emptyHeading(), self::emptyDescription(), 'settings')
            ->recordActions([ViewAction::make(self::class)]);
    }

    public static function infolist(InfolistSchema $schema): InfolistSchema
    {
        return $schema->schema([
            Section::make(Display::section('identity'))->columns(2)->schema([
                TextEntry::make('code')->label(Display::field('code')),
                TextEntry::make('name')->label(Display::field('name')),
                TextEntry::make('id')->label(Display::field('id')),
                DateTimeEntry::make('created_at')->label(Display::field('created_at')),
                TextEntry::make('members_count')
                    ->label(Display::field('members_count'))
                    ->formatUsing(static fn (mixed $value, Program $program): string => (string) $program->members()->count()),
                TextEntry::make('wallets_count')
                    ->label(Display::field('wallets_count'))
                    ->formatUsing(static fn (mixed $value, Program $program): string => (string) $program->wallets()->count()),
                TextEntry::make('active_plans_count')->label(Display::field('active_plans_count')),
                TextEntry::make('latest_period_status')
                    ->label(Display::field('latest_period'))
                    ->formatUsing(static fn (mixed $status, Program $program): ?string => self::latestPeriod($program))
                    ->placeholder(Display::none()),
            ]),
        ]);
    }

    public static function pages(): array
    {
        return [
            'index' => ListPrograms::class,
            'view' => ViewProgram::class,
        ];
    }

    public static function relationManagers(): array
    {
        return [
            ProgramMembersRelation::class,
            ProgramPlansRelation::class,
            ProgramPeriodsRelation::class,
        ];
    }

    private static function latestPeriod(Program $program): ?string
    {
        $status = $program->getAttribute('latest_period_status');

        if ($status === null) {
            return null;
        }

        return __('mlm::mlm.values.latest_period', [
            'status' => Display::status('period', (string) $status),
            'from' => Display::moment($program->getAttribute('latest_period_from')),
            'until' => Display::moment($program->getAttribute('latest_period_until')),
        ]);
    }
}
