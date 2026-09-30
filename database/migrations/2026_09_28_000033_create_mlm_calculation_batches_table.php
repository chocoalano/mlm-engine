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

        // One hybrid calculation (ADR-028): every commission component of one
        // plan version, over one range, funded from one source account, under
        // one idempotency key. Its facts never change; only its status moves,
        // once, from open to completed. No existing run is ever gathered into
        // one: only the hybrid engine creates batches.
        Schema::create('mlm_calculation_batches', static function (Blueprint $table) use ($exact): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('program_id')->constrained('mlm_programs')->restrictOnDelete();
            $table->foreignUlid('plan_version_id')->constrained('mlm_plan_versions')->restrictOnDelete();
            $table->foreignUlid('source_ledger_account_id')->constrained('mlm_ledger_accounts')->restrictOnDelete();

            $table->string('idempotency_key', 191)->collation($exact);

            // The closed range [from, until) every component is calculated over.
            $table->dateTime('from_at');
            $table->dateTime('until_at');

            // `open` until every component's run is linked, then `completed`.
            $table->string('status', 16)->collation($exact);
            $table->dateTime('completed_at')->nullable();

            $table->timestamps();

            // Replaying a batch is recognised by its key, within a program.
            $table->unique(['program_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_calculation_batches');
    }
};
