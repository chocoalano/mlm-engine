<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Plans;

use Illuminate\Database\Eloquent\Builder;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanVersion;
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
 * Plans and their versions, read-only. Editing, cloning, publishing and
 * activating belong to the Plan Builder (Phase 3.9B).
 */
final class PlanResource extends MlmResource
{
    protected static string $model = Plan::class;

    protected static ?string $slug = 'mlm-plans';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationIcon = 'copy';

    protected static int $navigationSort = MlmNavigation::PLANS;

    protected static function viewPermission(): string
    {
        return MlmPermission::PLANS_VIEW;
    }

    protected static function translationKey(): string
    {
        return 'plan';
    }

    /**
     * @return Builder<Plan>
     */
    public static function query(): Builder
    {
        return parent::query()
            ->select('mlm_plans.*')
            ->addSelect(['active_version' => PlanVersion::query()
                ->select('version')
                ->whereColumn('mlm_plan_versions.plan_id', 'mlm_plans.id')
                ->where('status', PlanVersionStatus::Active->value)
                ->limit(1)]);
    }

    public static function table(TableSchema $table): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('program.name')->label(Display::field('program')),
                TextColumn::make('code')->label(Display::field('code'))->searchable()->sortable(),
                TextColumn::make('name')->label(Display::field('name'))->searchable()->sortable(),
                TextColumn::make('active_version')
                    ->label(Display::field('active_version'))
                    ->formatUsing(static fn (mixed $version): ?string => self::version($version))
                    ->placeholder(Display::none())
                    ->queryable(false),
                NumberColumn::make('versions_count')->label(Display::field('versions_count'))->counts('versions')->queryable(false),
            ])
            ->defaultSort('code', SortDirection::Ascending)
            ->emptyState(self::emptyHeading(), self::emptyDescription(), 'copy')
            ->recordActions([ViewAction::make(self::class)]);
    }

    public static function infolist(InfolistSchema $schema): InfolistSchema
    {
        return $schema->schema([
            Section::make(Display::section('identity'))->columns(2)->schema([
                TextEntry::make('program.name')->label(Display::field('program')),
                TextEntry::make('code')->label(Display::field('code')),
                TextEntry::make('name')->label(Display::field('name')),
                TextEntry::make('active_version')
                    ->label(Display::field('active_version'))
                    ->formatUsing(static fn (mixed $version): ?string => self::version($version))
                    ->placeholder(Display::none()),
                TextEntry::make('id')->label(Display::field('id')),
                DateTimeEntry::make('created_at')->label(Display::field('created_at')),
            ]),
        ]);
    }

    public static function pages(): array
    {
        return [
            'index' => ListPlans::class,
            'view' => ViewPlan::class,
        ];
    }

    public static function relationManagers(): array
    {
        return [PlanVersionsRelation::class];
    }

    private static function version(mixed $version): ?string
    {
        return $version === null ? null : __('mlm::mlm.values.version', ['version' => $version]);
    }
}
