<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Calculation;

use Closure;
use Illuminate\Database\Connection;
use PandaBear\Mlm\Exceptions\InvalidCalculationRun;

/**
 * @internal
 *
 * Runs work in a transaction of its own whose reads all see one database
 * snapshot, taken at its first read:
 *
 * - MySQL and MariaDB: REPEATABLE READ, set for the next transaction only —
 *   never for the session — just before it begins;
 * - PostgreSQL: REPEATABLE READ, set as the transaction's first statement,
 *   before any read takes the snapshot;
 * - SQLite: a plain transaction, which reads one consistent state: another
 *   connection's write either waits for it or is not seen by it.
 *
 * It must be the outermost transaction: inside a caller's, the isolation
 * and the snapshot would already be decided, so it refuses to start.
 */
final class ReadSnapshot
{
    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $work
     * @return TResult
     */
    public static function run(Connection $db, Closure $work): mixed
    {
        if ($db->transactionLevel() > 0) {
            throw InvalidCalculationRun::insideTransaction((string) $db->getName());
        }

        return match ($db->getDriverName()) {
            'mysql', 'mariadb' => self::mysql($db, $work),
            'pgsql' => $db->transaction(static function () use ($db, $work): mixed {
                $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

                return $work();
            }),
            default => $db->transaction($work),
        };
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $work
     * @return TResult
     */
    private static function mysql(Connection $db, Closure $work): mixed
    {
        // Without SESSION or GLOBAL, this applies to the next transaction
        // only, and must come before it begins.
        $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

        return $db->transaction($work);
    }
}
