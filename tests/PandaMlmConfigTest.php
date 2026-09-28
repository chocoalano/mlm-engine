<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use PandaBear\Mlm\Exceptions\InvalidMlmConfiguration;
use PandaBear\Mlm\Support\PandaMlmConfig;
use PandaBear\Mlm\Tests\Database\ExternalDatabase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PandaMlmConfigTest extends TestCase
{
    public function test_the_provider_merges_the_package_config(): void
    {
        // Both sides read the same environment, so this holds whatever the
        // developer's shell has set.
        $expected = $this->packageConfig();

        // A run against a real database points the package at it, as an
        // application would.
        $external = ExternalDatabase::selected();

        if ($external !== null) {
            $expected['database']['connection'] = $external->connection;
        }

        $this->assertSame($expected, $this->app->make('config')->get('mlm'));
    }

    public function test_every_key_is_present(): void
    {
        $config = $this->app->make('config');

        foreach (['database.connection', 'queue.connection', 'queue.name', 'cache.store', 'cache.prefix'] as $key) {
            $this->assertTrue($config->has("mlm.{$key}"), "mlm.{$key} is missing.");
        }
    }

    public function test_the_defaults_without_any_mlm_environment_variable(): void
    {
        $this->assertSame([
            'database' => ['connection' => null],
            'queue' => ['connection' => null, 'name' => 'mlm'],
            'cache' => ['store' => null, 'prefix' => 'mlm'],
        ], $this->packageConfig([]));
    }

    public function test_each_value_reads_its_mlm_environment_variable(): void
    {
        $this->assertSame([
            'database' => ['connection' => 'mlm_db'],
            'queue' => ['connection' => 'redis', 'name' => 'payouts'],
            'cache' => ['store' => 'redis', 'prefix' => 'tenant-a'],
        ], $this->packageConfig([
            'MLM_DB_CONNECTION' => 'mlm_db',
            'MLM_QUEUE_CONNECTION' => 'redis',
            'MLM_QUEUE_NAME' => 'payouts',
            'MLM_CACHE_STORE' => 'redis',
            'MLM_CACHE_PREFIX' => 'tenant-a',
        ]));
    }

    public function test_the_container_resolves_it(): void
    {
        $this->assertInstanceOf(PandaMlmConfig::class, $this->app->make(PandaMlmConfig::class));
    }

    public function test_it_reads_the_application_config(): void
    {
        $this->app->make('config')->set('mlm', [
            'database' => ['connection' => 'mlm_db'],
            'queue' => ['connection' => 'redis', 'name' => 'payouts'],
            'cache' => ['store' => 'redis', 'prefix' => 'tenant-a'],
        ]);

        $config = $this->app->make(PandaMlmConfig::class);

        $this->assertSame('mlm_db', $config->databaseConnection());
        $this->assertSame('redis', $config->queueConnection());
        $this->assertSame('payouts', $config->queueName());
        $this->assertSame('redis', $config->cacheStore());
        $this->assertSame('tenant-a', $config->cachePrefix());
    }

    public function test_a_config_change_is_seen_by_the_next_resolution(): void
    {
        $this->app->make(PandaMlmConfig::class);

        $this->app->make('config')->set('mlm.queue.name', 'payouts');

        $this->assertSame('payouts', $this->app->make(PandaMlmConfig::class)->queueName());
    }

    public function test_an_empty_connection_or_store_means_the_application_default(): void
    {
        $this->app->make('config')->set('mlm.database.connection', '');
        $this->app->make('config')->set('mlm.queue.connection', '');
        $this->app->make('config')->set('mlm.cache.store', '');

        $config = $this->app->make(PandaMlmConfig::class);

        $this->assertNull($config->databaseConnection());
        $this->assertNull($config->queueConnection());
        $this->assertNull($config->cacheStore());
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function invalidValues(): array
    {
        return [
            'empty queue name' => ['mlm.queue.name', ''],
            'blank queue name' => ['mlm.queue.name', '   '],
            'missing queue name' => ['mlm.queue.name', null],
            'empty cache prefix' => ['mlm.cache.prefix', ''],
            'missing cache prefix' => ['mlm.cache.prefix', null],
            'non-string connection' => ['mlm.database.connection', 123],
            'non-string store' => ['mlm.cache.store', ['redis']],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_it_refuses_an_invalid_value(string $key, mixed $value): void
    {
        $this->app->make('config')->set($key, $value);

        $this->expectException(InvalidMlmConfiguration::class);
        $this->expectExceptionMessage("[{$key}]");

        $this->app->make(PandaMlmConfig::class);
    }

    /**
     * Evaluates `config/mlm.php` with every `MLM_*` variable removed, then
     * the given ones set — so the result never depends on the shell the
     * suite runs in. Without `$env`, reads the environment as it is.
     *
     * @param  array<string, string>|null  $env
     * @return array<string, mixed>
     */
    private function packageConfig(?array $env = null): array
    {
        $path = dirname(__DIR__).'/config/mlm.php';

        if ($env === null) {
            return require $path;
        }

        $server = $_SERVER;
        $environment = $_ENV;
        $putenv = array_filter(getenv(), static fn (string $name): bool => str_starts_with($name, 'MLM_'), ARRAY_FILTER_USE_KEY);

        try {
            foreach (array_keys($putenv) as $name) {
                putenv($name);
            }

            $_SERVER = array_filter($_SERVER, static fn (string|int $name): bool => ! str_starts_with((string) $name, 'MLM_'), ARRAY_FILTER_USE_KEY);
            $_ENV = array_filter($_ENV, static fn (string|int $name): bool => ! str_starts_with((string) $name, 'MLM_'), ARRAY_FILTER_USE_KEY);

            foreach ($env as $name => $value) {
                $_SERVER[$name] = $_ENV[$name] = $value;
            }

            return require $path;
        } finally {
            $_SERVER = $server;
            $_ENV = $environment;

            foreach ($putenv as $name => $value) {
                putenv("{$name}={$value}");
            }
        }
    }
}
