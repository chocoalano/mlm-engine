<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Plans;

use Illuminate\Database\Eloquent\Model;
use PandaBear\Mlm\Models\PlanComponent;
use PandaBear\Mlm\Models\PlanRule;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Support\Display;
use PandaBear\Mlm\Panel\Support\MlmRelationManager;
use PandaPanel\Tables\Columns\NumberColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

/**
 * A version's components in their order, each with its driver, strategy,
 * parameters and rules, as stored. Configuration is only ever shown —
 * never evaluated here.
 */
final class PlanVersionComponentsRelation extends MlmRelationManager
{
    protected static string $relationship = 'components';

    /** @var list<string> */
    protected static array $with = ['rules'];

    protected static function viewPermission(): string
    {
        return MlmPermission::PLANS_VIEW;
    }

    protected static function titleKey(): string
    {
        return 'components';
    }

    public static function table(TableSchema $table, Model $owner): TableSchema
    {
        return $table->columns([
            NumberColumn::make('position')->label(Display::field('position')),
            TextColumn::make('key')->label(Display::field('key')),
            TextColumn::make('name')->label(Display::field('name')),
            TextColumn::make('driver')->label(Display::field('driver')),
            TextColumn::make('strategy')
                ->label(Display::field('strategy'))
                ->formatUsing(static fn (mixed $value, PlanComponent $component): mixed => $component->parameters['strategy'] ?? null)
                ->placeholder(Display::none())
                ->queryable(false),
            TextColumn::make('parameters')
                ->label(Display::field('parameters'))
                ->formatUsing(static fn (mixed $value, PlanComponent $component): ?string => Display::json($component->parameters))
                ->wrap()
                ->placeholder(Display::none())
                ->queryable(false),
            TextColumn::make('rules')
                ->label(Display::field('rules'))
                ->formatUsing(static fn (mixed $value, PlanComponent $component): ?string => $component->rules->isEmpty() ? null : $component->rules
                    ->map(static fn (PlanRule $rule): string => "{$rule->position}. {$rule->key} ({$rule->name}): ".Display::json($rule->definition->toArray()))
                    ->implode("\n"))
                ->wrap()
                ->placeholder(Display::none())
                ->queryable(false),
        ]);
    }
}
