<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Panel;

use PandaBear\Mlm\Genealogy\SponsorGenealogy;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Models\SponsorEdge;
use PandaBear\Mlm\PandaMlmPlugin;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Resources\Members\MemberActions;
use PandaBear\Mlm\Panel\Resources\Plans\PlanVersionComponentsRelation;
use PandaBear\Mlm\Panel\Resources\Plans\PlanVersionRulesRelation;
use PandaBear\Mlm\Panel\Resources\Programs\ProgramActions;
use PandaBear\Mlm\Planning\PlanDefinitionEditor;
use PandaBear\Mlm\Planning\PlanVersionLifecycle;
use PandaBear\Mlm\Program\ProgramManager;
use PandaPanel\Actions\Action;
use PandaPanel\Infolists\InfolistSchema;
use PandaPanel\Tables\TableSchema;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Every action that changes anything answers to exactly one operate
 * capability (ADR-031): holding one never lets an operator run another
 * area's actions, and viewing never lets anyone run any.
 */
final class AuthorizationMatrixTest extends PanelTestCase
{
    /**
     * Each operate capability and the actions it — and only it — runs.
     */
    private const MATRIX = [
        MlmPermission::PROGRAMS_OPERATE => ['new-program', 'open-system-account'],
        MlmPermission::MEMBERS_OPERATE => ['new-member'],
        MlmPermission::NETWORK_OPERATE => ['configure-matrix', 'assign-sponsor', 'place', 'place-binary', 'adopt-binary', 'place-matrix', 'adopt-matrix'],
        MlmPermission::PLANS_OPERATE => [
            'new-plan', 'new-draft', 'clone-version', 'validate-version', 'publish-version', 'activate-version', 'archive-version', 'clone-to-draft',
            'add-component', 'change-component', 'add-rule', 'remove-component', 'change-rule', 'remove-rule',
        ],
        MlmPermission::PERIODS_OPERATE => ['open-period', 'calculate', 'finalize', 'release'],
        MlmPermission::COMMISSIONS_OPERATE => ['mark-pending', 'approve-commission', 'post-commission', 'cancel-commission'],
        MlmPermission::PAYOUTS_OPERATE => [
            'new-payout-request', 'approve', 'start-processing', 'settle', 'fail', 'cancel',
            'new-payout-batch', 'add-request', 'seal', 'start-batch', 'complete', 'cancel-batch',
        ],
    ];

    public function test_each_operate_capability_runs_its_own_area_and_nothing_else(): void
    {
        $actions = $this->mutatingActions();

        $this->assertEqualsCanonicalizing(array_merge(...array_values(self::MATRIX)), array_keys($actions), 'An action changes state without a place in the matrix.');
        $this->assertEqualsCanonicalizing(MlmPermission::operate(), array_keys(self::MATRIX));

        foreach ([null, ...MlmPermission::operate()] as $held) {
            $this->grant(...MlmPermission::view(), ...($held === null ? [] : [$held]));

            foreach (self::MATRIX as $permission => $names) {
                foreach ($names as $name) {
                    $this->assertSame(
                        $permission === $held,
                        $actions[$name]->isAuthorizedFor(null),
                        sprintf('[%s] with %s', $name, $held ?? 'view capabilities only'),
                    );
                }
            }
        }
    }

    /**
     * Panda Panel wraps an action in a database transaction unless told not
     * to. Every service here owns its transaction — and the period
     * calculator refuses to run inside a caller's — so every action that
     * changes state opts out.
     */
    public function test_every_action_leaves_its_transaction_to_its_service(): void
    {
        foreach ($this->mutatingActions() as $name => $action) {
            $this->assertFalse($action->hasDatabaseTransaction(), "[{$name}] runs inside the panel's transaction.");
        }
    }

    /**
     * The panel asks the capability before it runs an action, but a handler
     * reached another way asks again itself: run directly, without the
     * capability, each is refused before its service is called.
     */
    public function test_each_handler_asks_its_capability_itself(): void
    {
        $programs = $this->app->make(ProgramManager::class);
        $program = $programs->create('MAIN', 'Main');
        $member = $programs->join($program, 'A', now());
        $sponsor = $programs->join($program, 'B', now());
        $version = $this->app->make(PlanVersionLifecycle::class)->draft($programs->addPlan($program, 'P', 'P'));
        $resolved = $this->spyOn([SponsorGenealogy::class, PlanDefinitionEditor::class, ProgramManager::class]);
        $resolved[ProgramManager::class] = 0;

        $this->grant(...MlmPermission::view());

        foreach ([
            static fn () => MemberActions::assignSponsor()->execute($member, ['sponsor_id' => $sponsor->id]),
            static fn () => ProgramActions::creation()->executeWithoutRecord(['code' => 'X', 'name' => 'X']),
            static fn () => PlanVersionComponentsRelation::table(TableSchema::make(), $version)->getHeaderActions()[0]->executeWithoutRecord(['key' => 'k', 'name' => 'K', 'driver' => 'rank.ladder', 'parameters_json' => '{}']),
        ] as $run) {
            try {
                $run();
                $this->fail('A handler ran without its capability.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }

        $this->assertSame([0, 0, 0], array_values($resolved->getArrayCopy()));
        $this->assertSame([0, 1], [SponsorEdge::query()->count(), Program::query()->count()]);
        $this->assertSame(0, $version->components()->count());
    }

    /**
     * Every action on every table, relation table and detail page of the
     * surface, except the links to other screens.
     *
     * @return array<string, Action>
     */
    private function mutatingActions(): array
    {
        $version = $this->app->make(PlanVersionLifecycle::class)->draft(
            $this->app->make(ProgramManager::class)->addPlan($this->app->make(ProgramManager::class)->create('MAIN', 'Main'), 'P', 'P'),
        );

        $tables = [];
        $found = [];

        foreach (PandaMlmPlugin::RESOURCES as $resource) {
            $tables[] = $resource::table(TableSchema::make());

            foreach ($resource::infolist(InfolistSchema::make())->allActions() as $action) {
                $found[] = $action;
            }
        }

        $tables[] = PlanVersionComponentsRelation::table(TableSchema::make(), $version);
        $tables[] = PlanVersionRulesRelation::table(TableSchema::make(), $version);

        foreach ($tables as $table) {
            array_push($found, ...$table->getHeaderActions(), ...$table->getRecordActions(), ...$table->getEmptyStateActions(), ...$table->getBulkActions());
        }

        $actions = [];

        foreach ($found as $action) {
            if ($action->type()->value === 'link') {
                continue;
            }

            $actions[$action->getName()] = $action;
        }

        return $actions;
    }
}
