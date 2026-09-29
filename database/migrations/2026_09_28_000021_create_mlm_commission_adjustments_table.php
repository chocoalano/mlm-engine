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

        // Why and how a calculated commission was corrected: written once, when
        // the correction completes, and never changed.
        Schema::create('mlm_commission_adjustments', static function (Blueprint $table) use ($exact): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('program_id')->constrained('mlm_programs')->restrictOnDelete();
            $table->foreignUlid('commission_id')->constrained('mlm_commissions')->restrictOnDelete();

            $table->string('type', 64)->collation($exact);

            // The record that required the correction, such as a volume
            // entry's reversal.
            $table->string('source_type', 64)->collation($exact);
            $table->string('source_id', 191)->collation($exact);

            // Signed millionths: a clawback is the commission's amount, negated.
            $table->bigInteger('amount_millionths');

            // The business moment of the correction.
            $table->dateTime('occurred_at');

            $table->string('outcome', 32)->collation($exact);

            // The ledger reversal that moved the money back, when money had
            // moved.
            $table->foreignUlid('ledger_transaction_id')->nullable()->unique()->constrained('mlm_ledger_transactions')->restrictOnDelete();

            $table->json('trace');

            $table->timestamps();

            // One correction of a commission per type and source.
            $table->unique(['commission_id', 'type', 'source_type', 'source_id'], 'mlm_commission_adjustments_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_commission_adjustments');
    }
};
