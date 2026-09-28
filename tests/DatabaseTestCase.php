<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

abstract class DatabaseTestCase extends TestCase
{
    /**
     * Laravel's own migrator, running the migrations the service provider
     * registered — never a hand-written schema. Each test gets a new
     * in-memory database, so nothing carries over between them.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->artisan('migrate')->assertSuccessful();
    }
}
