<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use PandaBear\Mlm\PandaMlmServiceProvider;
use PandaBear\Mlm\Tests\Database\ExternalDatabase;
use PandaPanel\PandaPanelServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * Testbench ignores package discovery, so both providers are listed by
     * hand: Panda Panel's for `PanelManager` and `panel:plugins`, and this
     * package's own.
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            PandaPanelServiceProvider::class,
            PandaMlmServiceProvider::class,
        ];
    }

    /**
     * A fresh in-memory SQLite database per test, with foreign keys enforced
     * so the schema's constraints are exercised rather than assumed.
     *
     * With MLM_TEST_DATABASE set, the same suite runs against a real MySQL
     * or PostgreSQL database instead: it becomes the default connection and,
     * as in production, the one `mlm.database.connection` names.
     */
    protected function defineEnvironment($app): void
    {
        $config = $app->make('config');

        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $external = ExternalDatabase::selected();

        if ($external !== null) {
            $config->set("database.connections.{$external->connection}", $external->config());
            $config->set('database.default', $external->connection);
            $config->set('mlm.database.connection', $external->connection);
        }
    }

    /**
     * A real test database outlives the test, so each test starts with it
     * empty. The in-memory SQLite database is new for every test already.
     */
    protected function defineDatabaseMigrations(): void
    {
        if (ExternalDatabase::selected() !== null) {
            $this->app->make('db')->connection()->getSchemaBuilder()->dropAllTables();
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function composerJson(): array
    {
        return json_decode(
            (string) file_get_contents(dirname(__DIR__).'/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
