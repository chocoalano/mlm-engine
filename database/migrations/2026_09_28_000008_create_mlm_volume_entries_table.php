<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PandaBear\Mlm\Support\PandaMlmConfig;

return new class extends Migration
{
    /**
     * The migrator builds this migration's schema on the connection named
     * here, which is the one the package's models read from.
     */
    public function getConnection(): ?string
    {
        return Container::getInstance()->make(PandaMlmConfig::class)->databaseConnection();
    }

    public function up(): void
    {
        // Immutable history: an entry is never updated or deleted, and a
        // correction is a second entry that reverses the first.
        Schema::create('mlm_volume_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Denormalised from the member on purpose: an explicit boundary
            // and program-scoped keys. The recorder takes it from the stored
            // member, so the two always agree.
            $table->foreignUlid('program_id')->constrained('mlm_programs')->restrictOnDelete();
            $table->foreignUlid('member_id')->constrained('mlm_members')->restrictOnDelete();

            $table->string('type', 64);

            // Millionths of a unit, as an integer: exact on every database.
            // DECIMAL is stored as a float on SQLite.
            $table->bigInteger('quantity_millionths');

            $table->string('source_type', 64);
            $table->string('source_id', 128);
            $table->string('idempotency_key', 191);
            $table->dateTime('effective_at');

            // At most one reversal per entry.
            $table->foreignUlid('reversal_of_id')->nullable()->unique()->constrained('mlm_volume_entries')->restrictOnDelete();

            $table->timestamps();

            // Replaying a command is recognised by its key, within a program.
            $table->unique(['program_id', 'idempotency_key']);

            // A member's total of one type, optionally over an effective range.
            $table->index(['member_id', 'type', 'effective_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_volume_entries');
    }
};
