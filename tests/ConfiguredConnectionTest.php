<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Illuminate\Support\Facades\Schema;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Planning\PlanVersionLifecycle;

/**
 * `mlm.database.connection` names a connection other than the default, and
 * the migrations, the models and the lifecycle's transactions all follow it.
 */
final class ConfiguredConnectionTest extends DatabaseTestCase
{
    private const TABLES = ['mlm_programs', 'mlm_members', 'mlm_plans', 'mlm_plan_versions'];

    private const MODELS = [Program::class, Member::class, Plan::class, PlanVersion::class];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');

        $config->set('database.connections.mlm', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $config->set('mlm.database.connection', 'mlm');
    }

    public function test_the_migrations_build_on_the_configured_connection(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::connection('mlm')->hasTable($table), "{$table} is not on [mlm].");
            $this->assertFalse(Schema::connection('testing')->hasTable($table), "{$table} is on the default connection.");
        }
    }

    public function test_the_models_use_the_configured_connection(): void
    {
        foreach (self::MODELS as $model) {
            $this->assertSame('mlm', (new $model)->getConnectionName(), $model);
        }

        $program = Program::factory()->create();
        $member = Member::factory()->for($program)->create();

        $this->assertDatabaseHas('mlm_programs', ['id' => $program->id], 'mlm');
        $this->assertDatabaseHas('mlm_members', ['id' => $member->id], 'mlm');
        $this->assertTrue($program->members()->sole()->is($member));
    }

    public function test_the_lifecycle_runs_on_the_configured_connection(): void
    {
        $lifecycle = $this->app->make(PlanVersionLifecycle::class);
        $plan = Plan::factory()->create();

        $v1 = $lifecycle->activate($lifecycle->publish($lifecycle->markValidated($lifecycle->draft($plan))));
        $v2 = $lifecycle->activate($lifecycle->publish($lifecycle->markValidated($lifecycle->draft($plan))));

        $this->assertDatabaseHas('mlm_plan_versions', ['id' => $v1->id, 'status' => 'superseded'], 'mlm');
        $this->assertDatabaseHas('mlm_plan_versions', ['id' => $v2->id, 'status' => 'active'], 'mlm');
        $this->assertTrue($plan->currentActiveVersion()?->is($v2));
    }

    public function test_a_connection_set_on_the_model_still_wins(): void
    {
        foreach (self::MODELS as $model) {
            $this->assertSame('testing', (new $model)->setConnection('testing')->getConnectionName(), $model);
        }
    }
}
