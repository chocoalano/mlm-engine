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

        // One commission a run calculated, and where it is in its life. What
        // was calculated never changes; only its status, the moments it
        // reached each status, and its ledger transactions are recorded.
        Schema::create('mlm_commissions', function (Blueprint $table) use ($exact): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('calculation_run_id')->constrained('mlm_calculation_runs')->restrictOnDelete();

            // Denormalised from the run and the member on purpose: an explicit
            // program boundary. The engine takes both from stored rows.
            $table->foreignUlid('program_id')->constrained('mlm_programs')->restrictOnDelete();
            $table->foreignUlid('member_id')->constrained('mlm_members')->restrictOnDelete();

            $table->string('candidate_key', 191)->collation($exact);
            $table->string('currency', 3)->collation($exact);

            // Millionths, as an integer: exact on every database. Always
            // positive, and never more than one ledger posting holds.
            $table->bigInteger('amount_millionths');
            $table->dateTime('earned_at');

            // Inert JSON, stored with its keys in canonical order.
            $table->json('trace');

            $table->string('status', 16)->collation($exact);
            $table->dateTime('pending_at')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->dateTime('reversed_at')->nullable();

            // One ledger transaction posts a commission, and one reverses it.
            $table->foreignUlid('ledger_transaction_id')->nullable()->unique()->constrained('mlm_ledger_transactions')->restrictOnDelete();
            $table->foreignUlid('reversal_ledger_transaction_id')->nullable()->unique()->constrained('mlm_ledger_transactions')->restrictOnDelete();

            $table->timestamps();

            // A candidate key identifies a commission within its run. Named
            // explicitly, as every index name must fit MySQL's 64 characters.
            $table->unique(['calculation_run_id', 'candidate_key'], 'mlm_commissions_run_candidate_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_commissions');
    }
};
