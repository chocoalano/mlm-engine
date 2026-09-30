<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Plans;

use Illuminate\Database\Eloquent\Model;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Support\Display;
use PandaBear\Mlm\Panel\Support\MlmResource;
use PandaBear\Mlm\Planning\PlanDefinitionValidator;
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
use PandaPanel\Tables\Columns\NumberColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Enums\SortDirection;
use PandaPanel\Tables\TableSchema;

/**
 * One plan version, read-only: its lifecycle facts, and its components and
 * rules in order. Reached from its plan, so it stays out of the sidebar; its
 * list exists because a record page links back to one.
 */
final class PlanVersionResource extends MlmResource
{
    protected static string $model = PlanVersion::class;

    protected static ?string $slug = 'mlm-plan-versions';

    protected static bool $shouldRegisterNavigation = false;

    /** @var list<string> */
    protected static array $with = ['plan.program'];

    protected static function viewPermission(): string
    {
        return MlmPermission::PLANS_VIEW;
    }

    protected static function translationKey(): string
    {
        return 'plan_version';
    }

    public static function recordTitle(Model $record): string
    {
        /** @var PlanVersion $record */
        return $record->plan->name.' '.__('mlm::mlm.values.version', ['version' => $record->version]);
    }

    public static function table(TableSchema $table): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('plan.program.name')->label(Display::field('program')),
                TextColumn::make('plan.name')->label(Display::field('plan')),
                NumberColumn::make('version')->label(Display::field('version'))->sortable(),
                BadgeColumn::make('status')
                    ->label(Display::field('status'))
                    ->colors(Display::statusColors('plan_version'))
                    ->labels(Display::statusLabels('plan_version')),
                DateTimeColumn::make('activated_at')->label(Display::field('activated_at'))->placeholder(Display::none()),
            ])
            ->defaultSort('version', SortDirection::Descending)
            ->recordActions([ViewAction::make(self::class)]);
    }

    public static function infolist(InfolistSchema $schema): InfolistSchema
    {
        return $schema
            ->actions(PlanActions::lifecycle())
            ->schema([
                Section::make(Display::section('identity'))->columns(2)->schema([
                    TextEntry::make('plan.name')->label(Display::field('plan')),
                    TextEntry::make('version')->label(Display::field('version')),
                    BadgeEntry::make('status')
                        ->label(Display::field('status'))
                        ->formatUsing(static fn (mixed $status): ?string => Display::status('plan_version', $status))
                        ->colors(Display::statusColorsByLabel('plan_version')),
                    TextEntry::make('status_help')
                        ->label(Display::section('status'))
                        ->formatUsing(static fn (mixed $value, PlanVersion $version): ?string => Display::statusHelp('plan_version', $version->status)),
                    TextEntry::make('components_count')
                        ->label(Display::field('components_count'))
                        ->formatUsing(static fn (mixed $value, PlanVersion $version): string => (string) $version->components()->count()),
                    TextEntry::make('id')->label(Display::field('id')),
                ]),
                Section::make(Display::section('lifecycle'))->columns(3)->schema([
                    DateTimeEntry::make('created_at')->label(Display::field('created_at')),
                    DateTimeEntry::make('validated_at')->label(Display::field('validated_at'))->placeholder(Display::none()),
                    DateTimeEntry::make('published_at')->label(Display::field('published_at'))->placeholder(Display::none()),
                    DateTimeEntry::make('activated_at')->label(Display::field('activated_at'))->placeholder(Display::none()),
                    DateTimeEntry::make('superseded_at')->label(Display::field('superseded_at'))->placeholder(Display::none()),
                    DateTimeEntry::make('archived_at')->label(Display::field('archived_at'))->placeholder(Display::none()),
                ]),
                Section::make(Display::section('review'))
                    ->description(__('mlm::mlm.helpers.review'))
                    ->schema([
                        CodeEntry::make('definition')
                            ->label(Display::field('definition'))
                            ->language(CodeLanguage::Json)
                            ->formatUsing(static fn (mixed $value, PlanVersion $version): array => app(PlanDefinitionValidator::class)->definition($version))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function pages(): array
    {
        return [
            'index' => ListPlanVersions::class,
            'view' => ViewPlanVersion::class,
        ];
    }

    public static function relationManagers(): array
    {
        return [PlanVersionComponentsRelation::class, PlanVersionRulesRelation::class];
    }
}
