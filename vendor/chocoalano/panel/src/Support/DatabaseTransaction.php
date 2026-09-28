<?php

declare(strict_types=1);

namespace PandaPanel\Support;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Resolves whether a write runs inside a transaction.
 *
 * Three levels, most specific first: the action or page's own setting, then
 * the panel's, then on. `null` means "did not decide" rather than "off",
 * which is what lets a page inherit the panel while still being able to
 * override it in either direction.
 *
 * Outside a panel — a page controller called directly in a test, a queued
 * job — there is no panel to ask, and the answer is on. A write that
 * silently stopped being atomic outside the request cycle would be the worst
 * possible default.
 */
final class DatabaseTransaction
{
    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function run(?bool $override, Closure $callback): mixed
    {
        return self::enabled($override)
            ? DB::transaction($callback)
            : $callback();
    }

    public static function enabled(?bool $override): bool
    {
        return $override ?? panel()?->hasDatabaseTransactions() ?? true;
    }
}
