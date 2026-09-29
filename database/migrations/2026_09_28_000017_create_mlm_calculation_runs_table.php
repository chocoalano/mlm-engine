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

        // One successful calculation of one commission component, over one
        // closed range. Immutable: a new calculation is a new run.
        Schema::create('mlm_calculation_runs', function (Blueprint $table) use ($exact): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('program_id')->constrained('mlm_programs')->restrictOnDelete();
            $table->foreignUlid('plan_version_id')->constrained('mlm_plan_versions')->restrictOnDelete();
            $table->foreignUlid('plan_component_id')->constrained('mlm_plan_components')->restrictOnDelete();

            // Taken from the component when the run was made, and kept: the
            // strategy that ran, the currency it paid in, and the exact system
            // account its commissions are posted from.
            $table->string('strategy', 100)->collation($exact);
            $table->string('currency', 3)->collation($exact);
            $table->foreignUlid('source_ledger_account_id')->constrained('mlm_ledger_accounts')->restrictOnDelete();

            // The closed range [from, until) calculated.
            $table->dateTime('from_at');
            $table->dateTime('until_at');

            $table->string('idempotency_key', 191)->collation($exact);

            $table->timestamps();

            // Replaying a calculation is recognised by its key, within a program.
            $table->unique(['program_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_calculation_runs');
    }
};
