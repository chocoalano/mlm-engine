<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Plans;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Exceptions\InvalidRuleDefinition;
use PandaBear\Mlm\Exceptions\PlanVersionNotMutable;
use PandaBear\Mlm\Models\PlanRule;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Support\Display;
use PandaBear\Mlm\Panel\Support\DomainAction;
use PandaBear\Mlm\Panel\Support\MlmRelationManager;
use PandaBear\Mlm\Panel\Support\RuleForm;
use PandaBear\Mlm\Planning\PlanDefinitionEditor;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Tables\Columns\NumberColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

/**
 * Every rule of the version, in component order — a rank ladder's ranks
 * among them — and, while the version is a draft, their edits through
 * `PlanDefinitionEditor`.
 */
final class PlanVersionRulesRelation extends MlmRelationManager
{
    protected static string $relationship = 'rules';

    /** @var list<string> */
    protected static array $with = ['component'];

    private const REFUSALS = [InvalidPlanDefinition::class, InvalidRuleDefinition::class, PlanVersionNotMutable::class];

    protected static function viewPermission(): string
    {
        return MlmPermission::PLANS_VIEW;
    }

    protected static function titleKey(): string
    {
        return 'rules';
    }

    /**
     * The rules' own columns only. Through a join the framework's lookup
     * selects every column of both tables, and the component's `id` would
     * stand in for the rule's.
     *
     * @return Builder<PlanRule>
     */
    public static function query(Model $owner): Builder
    {
        return parent::query($owner)->select('mlm_plan_rules.*');
    }

    public static function table(TableSchema $table, Model $owner): TableSchema
    {
        /** @var PlanVersion $owner */
        $editable = static fn (?Model $rule = null): bool => $owner->isMutable();

        return $table
            ->columns([
                TextColumn::make('component.key')->label(Display::field('component')),
                NumberColumn::make('position')->label(Display::field('position')),
                TextColumn::make('key')->label(Display::field('key')),
                TextColumn::make('name')->label(Display::field('name')),
                TextColumn::make('definition')
                    ->label(Display::field('definition'))
                    ->formatUsing(static fn (mixed $value, PlanRule $rule): ?string => Display::json($rule->definition->toArray()))
                    ->wrap()
                    ->queryable(false),
            ])
            ->recordActions([
                DomainAction::withForm('change-rule', MlmPermission::PLANS_OPERATE)
                    ->icon('pencil')
                    ->visible(static fn (?Model $rule = null): bool => $editable() && $rule instanceof PlanRule && RuleForm::isFlat($rule))
                    ->schema(static fn (?Model $rule = null): FormSchema => RuleForm::schema($rule instanceof PlanRule ? $rule : null))
                    ->action(static function (PlanRule $rule, array $data = []): void {
                        $position = DomainAction::integer($data['position'] ?? null);

                        DomainAction::attempt('change-rule', MlmPermission::PLANS_OPERATE, self::REFUSALS, static fn (): PlanRule => app(PlanDefinitionEditor::class)->updateRule(
                            $rule,
                            name: (string) ($data['name'] ?? $rule->name),
                            definition: RuleForm::definition($data),
                            position: is_int($position) ? $position : null,
                        ));
                    }),
                DomainAction::confirmed('remove-rule', MlmPermission::PLANS_OPERATE)
                    ->icon('x')
                    ->visible($editable)
                    ->action(static function (PlanRule $rule): void {
                        DomainAction::attempt('remove-rule', MlmPermission::PLANS_OPERATE, self::REFUSALS, static fn () => app(PlanDefinitionEditor::class)->removeRule($rule));
                    }),
            ]);
    }
}
