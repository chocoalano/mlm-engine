<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Illuminate\Support\Facades\Schema;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Program;

/**
 * `mlm.database.connection` names a connection other than the default, and
 * both the migrations and the models follow it.
 */
final class ConfiguredConnectionTest extends DatabaseTestCase
{
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
        $this->assertTrue(Schema::connection('mlm')->hasTable('mlm_programs'));
        $this->assertTrue(Schema::connection('mlm')->hasTable('mlm_members'));

        $this->assertFalse(Schema::connection('testing')->hasTable('mlm_programs'));
        $this->assertFalse(Schema::connection('testing')->hasTable('mlm_members'));
    }

    public function test_the_models_use_the_configured_connection(): void
    {
        $program = Program::factory()->create();
        $member = Member::factory()->for($program)->create();

        $this->assertSame('mlm', (new Program)->getConnectionName());
        $this->assertSame('mlm', (new Member)->getConnectionName());

        $this->assertDatabaseHas('mlm_programs', ['id' => $program->id], 'mlm');
        $this->assertDatabaseHas('mlm_members', ['id' => $member->id], 'mlm');
        $this->assertTrue($program->members()->sole()->is($member));
    }

    public function test_a_connection_set_on_the_model_still_wins(): void
    {
        $this->assertSame('testing', (new Program)->setConnection('testing')->getConnectionName());
    }
}
