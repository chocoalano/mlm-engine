<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery history, and the secret each integration signs with.
 *
 * A separate migration rather than an edit to the one that created the table:
 * that one may already have run, and a migration that changes shape after it
 * has been applied somewhere is a migration that runs differently depending on
 * when you installed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('panel_integrations') && ! Schema::hasColumn('panel_integrations', 'secret')) {
            Schema::table('panel_integrations', function (Blueprint $table): void {
                // Encrypted at rest by the model's cast, so this holds
                // ciphertext and is longer than the 64 hex characters it
                // started as.
                $table->text('secret')->nullable();
            });
        }

        if (Schema::hasTable('panel_integration_deliveries')) {
            return;
        }

        Schema::create('panel_integration_deliveries', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('integration_id')
                ->constrained('panel_integrations')
                ->cascadeOnDelete();

            // Copied rather than joined. An integration's URL and trigger can
            // be edited, and a history that reported today's URL for last
            // week's delivery would be worse than no history.
            $table->string('trigger');
            $table->string('method', 10);
            $table->text('url');

            $table->uuid('delivery_id');

            $table->unsignedSmallInteger('status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error')->nullable();

            // Truncated, and never the headers — those hold the API keys this
            // screen exists to send, and a log of them would be a credential
            // store nobody meant to create.
            $table->text('request_body')->nullable();
            $table->text('response_body')->nullable();

            $table->timestamp('attempted_at');

            // Both queries this table answers: one integration's recent
            // deliveries, and the sweep that keeps it from growing.
            $table->index(['integration_id', 'id']);
            $table->index('attempted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_integration_deliveries');

        if (Schema::hasTable('panel_integrations') && Schema::hasColumn('panel_integrations', 'secret')) {
            Schema::table('panel_integrations', function (Blueprint $table): void {
                $table->dropColumn('secret');
            });
        }
    }
};
