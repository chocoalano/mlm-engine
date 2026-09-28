<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a resource's integrations are kept.
 *
 * Keyed by panel *and* resource slug rather than by a model class: the same
 * model can be two resources in two panels, with different audiences and
 * different reasons to notify anybody, and a class name in a data row is a
 * class name that survives a refactor it should not have.
 *
 * Created unconditionally — unlike `notifications`, nothing else owns a table
 * by this name — so `down()` may drop it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('panel_integrations')) {
            return;
        }

        Schema::create('panel_integrations', function (Blueprint $table): void {
            $table->id();

            $table->string('panel');
            $table->string('resource');
            $table->string('name');
            $table->string('trigger');

            $table->string('method', 10)->default('POST');
            $table->text('url');

            // Both are maps of name => value. JSON rather than a child table:
            // they are edited and read as a whole, never queried into.
            $table->json('headers')->nullable();
            $table->json('query')->nullable();

            $table->string('body_type', 10)->default('json');
            $table->longText('body')->nullable();

            $table->boolean('is_active')->default(true);

            // What the last attempt did, for the screen to show. Not a log:
            // one row per integration, overwritten, because a history that
            // grows without bound is a table somebody has to prune.
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_attempted_at')->nullable();

            $table->timestamps();

            // The dispatcher asks exactly this question on every write.
            $table->index(['panel', 'resource', 'trigger', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_integrations');
    }
};
