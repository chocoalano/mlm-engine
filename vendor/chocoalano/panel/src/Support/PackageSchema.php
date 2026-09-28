<?php

declare(strict_types=1);

namespace PandaPanel\Support;

use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Whether a rollback of this package may drop a table.
 *
 * A package migration that ships a *shared* table — `notifications` is
 * Laravel's, not the panel's — has a problem the application's own migrations
 * do not: `up()` has to skip the table when it is already there, and `down()`
 * then has no idea whether it is looking at a table it made or a table it
 * found. `dropIfExists()` in that position deletes an application's data on a
 * rollback that was only ever meant to undo this package.
 *
 * There is no place to write "I made this" that survives a deploy without the
 * package owning a table of its own, so ownership is *inferred*, from two
 * signals, and the answer is no unless both agree:
 *
 * - **No other migration claims the table.** A ran migration whose name ends in
 *   `create_<table>_table` — Laravel's own, `make:notifications-table`'s, or
 *   anybody else's — is an application saying out loud that the table is its
 *   own. Our own name is excluded, because a published copy of this migration
 *   is still this migration.
 * - **The columns are exactly the ones we would have created.** An extra
 *   column is somebody having built on the table, which is enough to leave it
 *   alone even when nothing claims it.
 *
 * Both checks fail safe. Not being sure leaves the table standing, which
 * costs an empty table nobody drops; being wrong the other way costs data.
 */
final class PackageSchema
{
    /**
     * Drops a table only when this package is the one that made it.
     *
     * @param  string  $migration  the dropping migration's own recorded name,
     *                             `basename(__FILE__, '.php')` at the call site
     * @param  list<string>  $columns  the exact column list `up()` creates
     */
    public static function dropIfOwned(string $table, string $migration, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        if (! self::isOwned($table, $migration, $columns)) {
            return;
        }

        Schema::drop($table);
    }

    /**
     * @param  list<string>  $columns
     */
    public static function isOwned(string $table, string $migration, array $columns): bool
    {
        return ! self::claimedByAnotherMigration($table, $migration)
            && self::columnsMatch($table, $columns);
    }

    /**
     * Whether some other migration says it created this table.
     *
     * Read from the migration repository rather than from the filesystem: what
     * matters is what *ran* against this database, and a project may keep its
     * migrations anywhere. A repository that cannot be read — no `migrations`
     * table, a connection that has gone away mid-rollback — answers "yes",
     * because an unanswerable ownership question is not a licence to drop.
     */
    private static function claimedByAnotherMigration(string $table, string $migration): bool
    {
        $suffix = 'create_'.$table.'_table';

        try {
            /** @var MigrationRepositoryInterface $repository */
            $repository = app('migration.repository');

            if (! $repository->repositoryExists()) {
                return false;
            }

            $ran = $repository->getRan();
        } catch (Throwable) {
            return true;
        }

        foreach ($ran as $name) {
            if ($name !== $migration && Str::endsWith($name, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $columns
     */
    private static function columnsMatch(string $table, array $columns): bool
    {
        $actual = Schema::getColumnListing($table);

        sort($actual);
        sort($columns);

        return $actual === $columns;
    }
}
