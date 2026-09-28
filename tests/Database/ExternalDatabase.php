<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Database;

use RuntimeException;

/**
 * A real MySQL or PostgreSQL database for the suite to run against, opted
 * into by MLM_TEST_DATABASE=mysql|pgsql and described entirely by
 * environment variables — never by anything committed:
 *
 *   MLM_TEST_MYSQL_DATABASE, MLM_TEST_MYSQL_USERNAME   (required)
 *   MLM_TEST_MYSQL_HOST, _PORT, _PASSWORD             (optional)
 *   MLM_TEST_PGSQL_... likewise
 *
 * Every test starts by dropping every table in that database, so it must be
 * one made for the purpose: its name must contain "test". The database
 * itself is never created or dropped here.
 */
final readonly class ExternalDatabase
{
    public const ENGINES = ['mysql', 'pgsql'];

    /**
     * @param  array<string, mixed>  $config
     */
    private function __construct(
        public string $engine,
        public string $connection,
        private array $config,
    ) {}

    /**
     * The database this run targets, or null for the default in-memory
     * SQLite run.
     */
    public static function selected(): ?self
    {
        $engine = self::env('MLM_TEST_DATABASE');

        return $engine === null ? null : self::for($engine);
    }

    public static function for(string $engine): self
    {
        if (! in_array($engine, self::ENGINES, true)) {
            throw new RuntimeException("MLM_TEST_DATABASE must be one of mysql, pgsql; \"{$engine}\" given.");
        }

        $prefix = 'MLM_TEST_'.strtoupper($engine).'_';
        $database = self::env($prefix.'DATABASE');
        $username = self::env($prefix.'USERNAME');

        if ($database === null || $username === null) {
            throw new RuntimeException("Set {$prefix}DATABASE and {$prefix}USERNAME to run the suite against {$engine}.");
        }

        if (! str_contains(strtolower($database), 'test')) {
            throw new RuntimeException("Refusing to use the {$engine} database \"{$database}\": the suite drops every table in it, so its name must contain \"test\".");
        }

        $config = [
            'driver' => $engine,
            'host' => self::env($prefix.'HOST') ?? '127.0.0.1',
            'port' => self::env($prefix.'PORT') ?? ($engine === 'mysql' ? '3306' : '5432'),
            'database' => $database,
            'username' => $username,
            'password' => self::env($prefix.'PASSWORD') ?? '',
            'prefix' => '',
        ];

        // As a Laravel application's default database configuration has it.
        $config += $engine === 'mysql'
            ? ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'strict' => true, 'engine' => null]
            : ['charset' => 'utf8', 'search_path' => 'public', 'sslmode' => 'prefer'];

        return new self($engine, "mlm_{$engine}_test", $config);
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
    }

    /**
     * Bounds how long a session waits for a lock, so a broken test fails
     * instead of hanging.
     */
    public function lockTimeoutStatement(int $seconds): string
    {
        return $this->engine === 'mysql'
            ? "SET SESSION innodb_lock_wait_timeout = {$seconds}"
            : "SET lock_timeout = '{$seconds}s'";
    }

    /**
     * Run inside a transaction: makes every INSERT into the table wait
     * until that transaction ends, while plain reads go on. On MySQL a
     * locking read of the whole table takes every record and gap lock; on
     * PostgreSQL a SHARE lock conflicts with inserts only.
     */
    public function insertGateStatement(string $table): string
    {
        return $this->engine === 'mysql'
            ? "SELECT id FROM {$table} FOR UPDATE"
            : "LOCK TABLE {$table} IN SHARE MODE";
    }

    /**
     * The statement each other session in this database is currently
     * waiting on a lock to run, one row per waiting session, in column
     * `statement`. On MySQL that is read from the InnoDB lock waits
     * themselves: information_schema.innodb_trx is a cached view that
     * can miss a transaction which is waiting.
     */
    public function waitingStatementsQuery(): string
    {
        return $this->engine === 'mysql'
            ? 'SELECT DISTINCT t.THREAD_ID, t.PROCESSLIST_INFO AS statement FROM performance_schema.data_lock_waits w'
                .' JOIN performance_schema.threads t ON t.THREAD_ID = w.REQUESTING_THREAD_ID'
                .' WHERE t.PROCESSLIST_DB = DATABASE()'
            : 'SELECT query AS statement FROM pg_stat_activity'
                ." WHERE datname = current_database() AND wait_event_type = 'Lock' AND pid <> pg_backend_pid()";
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? null : $value;
    }
}
