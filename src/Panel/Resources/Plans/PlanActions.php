<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Plans;

use Illuminate\Database\Eloquent\Model;
use PandaBear\Mlm\Exceptions\ConflictingProgramRecord;
use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Exceptions\InvalidPlanVersionTransition;
use PandaBear\Mlm\Exceptions\InvalidRuleDefinition;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Support\Display;
use PandaBear\Mlm\Panel\Support\DomainAction;
use PandaBear\Mlm\Panel\Support\Options;
use PandaBear\Mlm\Planning\PlanDefinitionCloner;
use PandaBear\Mlm\Planning\PlanVersionLifecycle;
use PandaBear\Mlm\Planning\PlanVersionStatus;
use PandaBear\Mlm\Program\ProgramManager;
use PandaPanel\Actions\Action;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Support\FormState;

/**
 * Plans and their versions, moved only through `PlanVersionLifecycle` and
 * `PlanDefinitionCloner`: a version is drafted, edited while a draft,
 * validated, published, activated and archived — never unlocked. A change
 * to a validated definition is a new draft cloned from it.
 */
final class PlanActions
{
    private const DEFINITION_REFUSALS = [InvalidPlanDefinition::class, InvalidRuleDefinition::class, InvalidPlanVersionTransition::class];

    public static function creation(): Action
    {
        return DomainAction::withForm('new-plan', MlmPermission::PLANS_OPERATE)
            ->icon('plus')
            ->schema(static fn (): FormSchema => FormSchema::make()->schema([
                Select::make('program_id')
                    ->label(Display::field('program'))
                    ->searchable()
                    ->existsIn(...Options::exists(Program::class))
                    ->optionsUsing(static fn (FormState $state, ?string $search = null): array => Options::programs($search))
                    ->required(),
                TextInput::make('code')->label(Display::field('code'))->required()->maxLength(64),
                TextInput::make('name')->label(Display::field('name'))->required()->maxLength(255),
            ]))
            ->tableAction(static function (array $data): void {
                DomainAction::attempt('new-plan', MlmPermission::PLANS_OPERATE, [ConflictingProgramRecord::class], static fn (): Plan => app(ProgramManager::class)->addPlan(
                    Program::query()->findOrFail($data['program_id'] ?? null),
                    (string) ($data['code'] ?? ''),
                    (string) ($data['name'] ?? ''),
                ));
            });
    }

    public static function newDraft(): Action
    {
        return DomainAction::confirmed('new-draft', MlmPermission::PLANS_OPERATE)
            ->icon('plus')
            ->action(static function (Plan $plan): void {
                DomainAction::attempt('new-draft', MlmPermission::PLANS_OPERATE, [], static fn (): PlanVersion => app(PlanVersionLifecycle::class)->draft($plan));
            });
    }

    /**
     * Any version of the plan, copied into a new draft: its components and
     * rules only — never its runs, commissions, periods or network state.
     */
    public static function cloneVersion(): Action
    {
        return DomainAction::withForm('clone-version', MlmPermission::PLANS_OPERATE)
            ->icon('copy')
            ->visible(static fn (?Model $plan = null): bool => $plan instanceof Plan && $plan->versions()->exists())
            ->schema(static fn (?Model $plan = null): FormSchema => FormSchema::make()->schema([
                Select::make('version_id')->label(Display::field('plan_version'))->options($plan instanceof Plan ? Options::versionsOf($plan) : [])->required(),
            ]))
            ->action(static function (Plan $plan, array $data = []): void {
                DomainAction::attempt('clone-version', MlmPermission::PLANS_OPERATE, self::DEFINITION_REFUSALS, static fn (): PlanVersion => app(PlanDefinitionCloner::class)
                    ->cloneToNewDraft($plan->versions()->findOrFail($data['version_id'] ?? null)));
            });
    }

    /**
     * @return list<Action>
     */
    public static function lifecycle(): array
    {
        return [
            self::step('validate-version', PlanVersionStatus::Validated, static fn (PlanVersionLifecycle $lifecycle, PlanVersion $version): PlanVersion => $lifecycle->markValidated($version)),
            self::step('publish-version', PlanVersionStatus::Published, static fn (PlanVersionLifecycle $lifecycle, PlanVersion $version): PlanVersion => $lifecycle->publish($version)),
            self::step('activate-version', PlanVersionStatus::Active, static fn (PlanVersionLifecycle $lifecycle, PlanVersion $version): PlanVersion => $lifecycle->activate($version)),
            self::step('archive-version', PlanVersionStatus::Archived, static fn (PlanVersionLifecycle $lifecycle, PlanVersion $version): PlanVersion => $lifecycle->archive($version)),
            DomainAction::confirmed('clone-to-draft', MlmPermission::PLANS_OPERATE)
                ->icon('copy')
                ->action(static function (PlanVersion $version): void {
                    DomainAction::attempt('clone-to-draft', MlmPermission::PLANS_OPERATE, self::DEFINITION_REFUSALS, static fn (): PlanVersion => app(PlanDefinitionCloner::class)->cloneToNewDraft($version));
                }),
        ];
    }

    /**
     * Offered where the version's status has this step next — the status's
     * own answer. The lifecycle decides, under the version's lock.
     *
     * @param  \Closure(PlanVersionLifecycle, PlanVersion): PlanVersion  $step
     */
    private static function step(string $name, PlanVersionStatus $to, \Closure $step): Action
    {
        return DomainAction::confirmed($name, MlmPermission::PLANS_OPERATE)
            ->icon($to === PlanVersionStatus::Archived ? 'x' : 'check')
            ->visible(static fn (?Model $version = null): bool => $version instanceof PlanVersion && $version->status->canTransitionTo($to))
            ->action(static function (PlanVersion $version) use ($name, $step): void {
                DomainAction::attempt($name, MlmPermission::PLANS_OPERATE, self::DEFINITION_REFUSALS, static fn (): PlanVersion => $step(app(PlanVersionLifecycle::class), $version));
            });
    }
}
