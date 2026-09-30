<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Plans;

use Illuminate\Database\Eloquent\Model;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Exceptions\InvalidRuleDefinition;
use PandaBear\Mlm\Exceptions\PlanVersionNotMutable;
use PandaBear\Mlm\Models\PlanComponent;
use PandaBear\Mlm\Models\PlanRule;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Support\ComponentForm;
use PandaBear\Mlm\Panel\Support\Display;
use PandaBear\Mlm\Panel\Support\DomainAction;
use PandaBear\Mlm\Panel\Support\MlmRelationManager;
use PandaBear\Mlm\Panel\Support\RuleForm;
use PandaBear\Mlm\Planning\PlanDefinitionEditor;
use PandaPanel\Actions\Action;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Tables\Columns\NumberColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

/**
 * A version's components in their order, each with its driver, strategy,
 * parameters and rules, as stored — and, while the version is a draft, the
 * Plan Builder's component edits, every one through `PlanDefinitionEditor`.
 * Configuration is only ever shown and stored as data, never evaluated
 * here.
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

    private const REFUSALS = [InvalidPlanDefinition::class, InvalidRuleDefinition::class, PlanVersionNotMutable::class];

    public static function table(TableSchema $table, Model $owner): TableSchema
    {
        /** @var PlanVersion $owner */
        return $table->headerActions([self::add($owner)])->recordActions([self::change($owner), self::addRule($owner), self::remove($owner)])->columns([
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

    /**
     * @return \Closure(): bool
     */
    private static function editable(PlanVersion $version): \Closure
    {
        return static fn (): bool => $version->isMutable();
    }

    private static function add(PlanVersion $version): Action
    {
        return DomainAction::withForm('add-component', MlmPermission::PLANS_OPERATE)
            ->icon('plus')
            ->visible(self::editable($version))
            ->schema(static fn (): FormSchema => ComponentForm::schema($version))
            ->tableAction(static function (array $data) use ($version): void {
                $driver = (string) ($data['driver'] ?? '');

                DomainAction::attempt('add-component', MlmPermission::PLANS_OPERATE, self::REFUSALS, static fn (): PlanComponent => app(PlanDefinitionEditor::class)->addComponent(
                    $version,
                    (string) ($data['key'] ?? ''),
                    $driver,
                    (string) ($data['name'] ?? ''),
                    ComponentForm::parameters($driver, $data),
                    self::position($data),
                ));
            });
    }

    private static function change(PlanVersion $version): Action
    {
        return DomainAction::withForm('change-component', MlmPermission::PLANS_OPERATE)
            ->icon('pencil')
            ->visible(self::editable($version))
            ->schema(static fn (?Model $component = null): FormSchema => ComponentForm::schema($version, $component instanceof PlanComponent ? $component : null))
            ->action(static function (PlanComponent $component, array $data = []): void {
                DomainAction::attempt('change-component', MlmPermission::PLANS_OPERATE, self::REFUSALS, static fn (): PlanComponent => app(PlanDefinitionEditor::class)->updateComponent(
                    $component,
                    name: (string) ($data['name'] ?? $component->name),
                    parameters: ComponentForm::parameters((string) $component->driver, $data),
                    position: self::position($data),
                ));
            });
    }

    private static function addRule(PlanVersion $version): Action
    {
        return DomainAction::withForm('add-rule', MlmPermission::PLANS_OPERATE)
            ->icon('plus')
            ->visible(self::editable($version))
            ->schema(static fn (): FormSchema => RuleForm::schema())
            ->action(static function (PlanComponent $component, array $data = []): void {
                DomainAction::attempt('add-rule', MlmPermission::PLANS_OPERATE, self::REFUSALS, static fn (): PlanRule => app(PlanDefinitionEditor::class)->addRule(
                    $component,
                    (string) ($data['key'] ?? ''),
                    (string) ($data['name'] ?? ''),
                    RuleForm::definition($data),
                    self::position($data),
                ));
            });
    }

    private static function remove(PlanVersion $version): Action
    {
        return DomainAction::confirmed('remove-component', MlmPermission::PLANS_OPERATE)
            ->icon('x')
            ->visible(self::editable($version))
            ->action(static function (PlanComponent $component): void {
                DomainAction::attempt('remove-component', MlmPermission::PLANS_OPERATE, self::REFUSALS, static fn () => app(PlanDefinitionEditor::class)->removeComponent($component));
            });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function position(array $data): ?int
    {
        $position = DomainAction::integer($data['position'] ?? null);

        return is_int($position) ? $position : null;
    }
}
