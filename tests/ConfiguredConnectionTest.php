<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Illuminate\Support\Facades\Schema;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\PlacementEdge;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Models\SponsorEdge;
use PandaBear\Mlm\Planning\PlanVersionLifecycle;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;

/**
 * `mlm.database.connection` names a connection other than the default, and
 * the migrations, the models, the plan lifecycle and both genealogies all
 * follow it.
 */
final class ConfiguredConnectionTest extends DatabaseTestCase
{
    use BuildsGenealogies;

    private const TABLES = ['mlm_programs', 'mlm_members', 'mlm_plans', 'mlm_plan_versions', 'mlm_sponsor_edges', 'mlm_genealogy_paths', 'mlm_placement_edges'];

    private const MODELS = [Program::class, Member::class, Plan::class, PlanVersion::class, SponsorEdge::class, PlacementEdge::class];

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

    public function test_the_sponsor_genealogy_writes_and_reads_on_the_configured_connection(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob', 'Charlie');

        $this->sponsorTree($members, ['Alice' => ['Bob'], 'Bob' => ['Charlie']]);

        $this->assertSame(['Alice > Bob', 'Bob > Charlie'], $this->genealogyState('mlm')['edges']);
        $this->assertCount(6, $this->genealogyState('mlm')['paths']);
        $this->assertSame(['Bob@1', 'Charlie@2'], $this->relatives($this->genealogy()->descendants($members['Alice'])));
        $this->assertSame(['Bob@1', 'Alice@2'], $this->relatives($this->genealogy()->ancestors($members['Charlie'])));
        $this->assertTrue($this->genealogy()->directSponsor($members['Bob'])?->is($members['Alice']));
        $this->assertSame(['Charlie'], $this->genealogy()->directMembers($members['Bob'])->pluck('member_code')->all());
    }

    public function test_the_placement_genealogy_writes_and_reads_on_the_configured_connection(): void
    {
        $members = $this->members(Program::factory()->create(), 'Alice', 'Bob', 'Charlie');

        $this->placementTree($members, ['Alice' => ['Bob'], 'Bob' => ['Charlie']]);

        $this->assertSame(['Alice > Bob', 'Bob > Charlie'], $this->placementState('mlm')['edges']);
        $this->assertCount(6, $this->placementState('mlm')['paths']);
        $this->assertSame(['Bob@1', 'Charlie@2'], $this->relatives($this->placement()->descendants($members['Alice'])));
        $this->assertSame(['Bob@1', 'Alice@2'], $this->relatives($this->placement()->ancestors($members['Charlie'])));
        $this->assertTrue($this->placement()->directParent($members['Bob'])?->is($members['Alice']));
        $this->assertSame(['Charlie'], $this->placement()->directChildren($members['Bob'])->pluck('member_code')->all());
    }

    public function test_a_connection_set_on_the_model_still_wins(): void
    {
        foreach (self::MODELS as $model) {
            $this->assertSame('testing', (new $model)->setConnection('testing')->getConnectionName(), $model);
        }
    }
}
