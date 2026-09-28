<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether this account uses an emailed code as its second factor.
 *
 * A timestamp rather than a boolean, for the same reason `email_verified_at`
 * is one: "when did they turn this on" is a question worth being able to
 * answer, and "is it on" is `!== null`.
 *
 * The codes themselves are not here. They live in the cache, keyed by user,
 * because a one-time code that expires in ten minutes has no business
 * outliving a cache flush — and a table of them is a table somebody has to
 * remember to prune.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'two_factor_email_confirmed_at')) {
            return;
        }

        // Positioned after Fortify's own column when the application has it,
        // and appended when it does not — this migration ships in a package
        // and cannot assume it runs second.
        $after = Schema::hasColumn('users', 'two_factor_confirmed_at')
            ? 'two_factor_confirmed_at'
            : null;

        Schema::table('users', function (Blueprint $table) use ($after): void {
            $column = $table->timestamp('two_factor_email_confirmed_at')->nullable();

            if ($after !== null) {
                $column->after($after);
            }
        });
    }

    /**
     * Drops the column, and only the column.
     *
     * Unlike the `notifications` table this one is unambiguously the package's
     * — nothing else has a reason to name a column `two_factor_email_confirmed_at`
     * — so a rollback that removes it is removing what it added. The table
     * itself is the application's and is never touched.
     *
     * The `hasTable` guard is not redundant: a rollback that runs after the
     * application's own `create_users_table` has already been rolled back
     * would otherwise ask a missing table for its columns.
     */
    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'two_factor_email_confirmed_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('two_factor_email_confirmed_at');
        });
    }
};
