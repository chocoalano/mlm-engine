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
        // Identifiers compare exactly on every database. MySQL's default
        // collations ignore letter case, so there they are made binary;
        // SQLite and PostgreSQL already compare exactly.
        $exact = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true) ? 'utf8mb4_bin' : null;

        // Immutable history: a transaction is never updated or deleted, and a
        // correction is a second transaction that reverses the first.
        Schema::create('mlm_ledger_transactions', function (Blueprint $table) use ($exact): void {
            // Declared before any foreign key: PostgreSQL refuses the
            // self-reference below (reversal_of_id -> id) until id is a key.
            $table->ulid('id');
            $table->primary('id');

            $table->foreignUlid('program_id')->constrained('mlm_programs')->restrictOnDelete();

            // One currency for every posting of the transaction.
            $table->string('currency', 3)->collation($exact);

            $table->string('type', 64)->collation($exact);
            $table->string('source_type', 64)->collation($exact);
            $table->string('source_id', 128)->collation($exact);
            $table->string('idempotency_key', 191)->collation($exact);

            // The business moment, given by the caller — not when the row
            // was written.
            $table->dateTime('occurred_at');

            // At most one reversal per transaction.
            $table->foreignUlid('reversal_of_id')->nullable()->unique()->constrained('mlm_ledger_transactions')->restrictOnDelete();

            $table->timestamps();

            // Replaying a command is recognised by its key, within a program.
            $table->unique(['program_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_ledger_transactions');
    }
};
