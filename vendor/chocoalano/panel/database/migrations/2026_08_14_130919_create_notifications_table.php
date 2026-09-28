<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PandaPanel\Support\PackageSchema;

/**
 * Laravel's own notifications table.
 *
 * The panel's notification centre reads through `Notifiable`, so the schema is
 * the framework's rather than one of the panel's own — `markAsRead()`,
 * `unreadNotifications`, and the rest all work without a line of code, and a
 * notification sent by anything else in the application shows up here too.
 *
 * `read_at` is indexed beside the owner because the only two questions ever
 * asked of this table are "what does this user have" and "how many has this
 * user not read".
 *
 * Being the framework's table rather than the panel's is exactly what makes
 * the rollback delicate: `up()` skips a table it finds, so `down()` is looking
 * at a table this package may never have made. See `PackageSchema`.
 */
return new class extends Migration
{
    /**
     * The columns `up()` creates, which is also the signature `down()` refuses
     * to drop anything that does not match.
     *
     * @var list<string>
     */
    private const COLUMNS = [
        'id',
        'type',
        'notifiable_type',
        'notifiable_id',
        'data',
        'read_at',
        'created_at',
        'updated_at',
    ];

    public function up(): void
    {
        // Most applications already have this table. A package migration that
        // assumed otherwise would fail the first `migrate` after install.
        if (Schema::hasTable('notifications')) {
            return;
        }

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });
    }

    /**
     * Drops the table only when this package is the one that made it.
     *
     * `dropIfExists()` here would delete an application's notifications on a
     * rollback that was only ever meant to undo this package — `up()` skips a
     * table it finds, so the two are not symmetric and pretending otherwise
     * loses data. `PackageSchema` decides, and leaves the table standing
     * whenever the answer is not a clear yes.
     *
     * `basename(__FILE__)` rather than a literal: a copy published into
     * `database/migrations` keeps the filename, so it still recognises itself.
     */
    public function down(): void
    {
        PackageSchema::dropIfOwned('notifications', basename(__FILE__, '.php'), self::COLUMNS);
    }
};
