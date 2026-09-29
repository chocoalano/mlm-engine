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

    /**
     * Named here: generated names are longer than MySQL allows.
     */
    private const IDENTITY = 'mlm_binary_pairing_corrections_identity_unique';

    private const INVALIDATED = 'mlm_binary_pairing_corrections_invalidated_foreign';

    public function up(): void
    {
        // Identifiers compare exactly on every database. MySQL's default
        // collations ignore letter case, so there they are made binary;
        // SQLite and PostgreSQL already compare exactly.
        $exact = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true) ? 'utf8mb4_bin' : null;

        // An earlier pair, partly undone (ADR-025): so much of one historical
        // allocation stopped counting, in the run a reversal of its source
        // fell in. The allocation, its result and its commission are never
        // changed; this row explains them. Written once, never changed.
        Schema::create('mlm_binary_pairing_corrections', static function (Blueprint $table) use ($exact): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('program_id')->constrained('mlm_programs')->restrictOnDelete();
            $table->foreignUlid('plan_component_id')->constrained('mlm_plan_components')->restrictOnDelete();

            // The run that made the correction.
            $table->foreignUlid('calculation_run_id')->constrained('mlm_calculation_runs')->restrictOnDelete();

            $table->foreignUlid('reversal_volume_entry_id')->constrained('mlm_volume_entries')->restrictOnDelete();
            $table->foreignUlid('original_volume_entry_id')->index()->constrained('mlm_volume_entries')->restrictOnDelete();

            // The historical pairing, the allocation of it that stopped
            // counting, and whose it was.
            $table->foreignUlid('binary_pairing_result_id')->index()->constrained('mlm_binary_pairing_results')->restrictOnDelete();
            $table->foreignUlid('invalidated_allocation_id')->index()->constrained('mlm_binary_pairing_allocations', indexName: self::INVALIDATED)->restrictOnDelete();
            $table->foreignUlid('member_id')->constrained('mlm_members')->restrictOnDelete();
            $table->string('invalidated_side', 5)->collation($exact);

            // Part of one allocation, so one BIGINT holds it; always positive.
            $table->bigInteger('quantity_millionths');

            // The commission the historical pairing earned, if any — for its
            // financial correction; nothing here changes it.
            $table->foreignUlid('commission_id')->nullable()->index()->constrained('mlm_commissions')->restrictOnDelete();

            $table->timestamps();

            // One reversal undoes an allocation once.
            $table->unique(['reversal_volume_entry_id', 'invalidated_allocation_id'], self::IDENTITY);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_binary_pairing_corrections');
    }
};
