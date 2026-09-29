<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Calculation;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\UniqueConstraintViolationException;
use PandaBear\Mlm\Exceptions\InvalidCalculationRun;
use Throwable;

/**
 * @internal
 *
 * Runs a stateful calculation (ADR-023) in a SERIALIZABLE transaction of its
 * own, so two runs of one component can never both consume the same state:
 *
 * - MySQL and MariaDB: SERIALIZABLE, set for the next transaction only —
 *   never for the session — just before it begins;
 * - PostgreSQL: SERIALIZABLE, set as the transaction's first statement;
 * - SQLite: a plain transaction; SQLite lets one writer at a time commit.
 *
 * When the database refuses an attempt to keep the transactions
 * serializable — a deadlock or serialization failure, as Laravel detects
 * them, or a unique key another run took first — the whole attempt is
 * rolled back and run again, from its first read, a bounded number of
 * times. The work must therefore be safe to repeat: a stateful calculation
 * reads, then writes only inside this transaction.
 *
 * Stateless calculations keep `ReadSnapshot`. Like it, this must be the
 * outermost transaction.
 */
final class StatefulCalculationTransaction
{
    use DetectsConcurrencyErrors;

    public const ATTEMPTS = 5;

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

        return (new self)->attempt($db, $work);
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $work
     * @return TResult
     */
    private function attempt(Connection $db, Closure $work): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return match ($db->getDriverName()) {
                    'mysql', 'mariadb' => $this->mysql($db, $work),
                    'pgsql' => $db->transaction(static function () use ($db, $work): mixed {
                        $db->statement('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');

                        return $work();
                    }),
                    default => $db->transaction($work),
                };
            } catch (Throwable $exception) {
                if ($attempt >= self::ATTEMPTS || ! $this->retryable($exception)) {
                    throw $exception;
                }

                // A short, growing pause lets the transaction that won finish.
                usleep(20_000 * $attempt);
            }
        }
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $work
     * @return TResult
     */
    private function mysql(Connection $db, Closure $work): mixed
    {
        // Without SESSION or GLOBAL, this applies to the next transaction
        // only, and must come before it begins.
        $db->statement('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');

        return $db->transaction($work);
    }

    private function retryable(Throwable $exception): bool
    {
        return $this->causedByConcurrencyError($exception) || $exception instanceof UniqueConstraintViolationException;
    }
}
