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
     * Named here: a program's periods by their start, for the overlap check
     * and for finding the period a business moment falls in.
     */
    private const TIMELINE = 'mlm_commission_periods_timeline_index';

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
        $exact = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true) ? 'utf8mb4_bin' : null;

        // A commission period (ADR-029): one program's plan version, calculated
        // over one range [from, until) and funded from one source account,
        // then finalized and released no earlier than release_at. A program's
        // periods never overlap. Its facts never change; only its status and
        // the moments it moved do. No period is inferred for past runs.
        Schema::create('mlm_commission_periods', static function (Blueprint $table) use ($exact): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('program_id')->constrained('mlm_programs')->restrictOnDelete();
            $table->foreignUlid('plan_version_id')->constrained('mlm_plan_versions')->restrictOnDelete();
            $table->foreignUlid('source_ledger_account_id')->constrained('mlm_ledger_accounts')->restrictOnDelete();

            $table->string('idempotency_key', 191)->collation($exact);

            $table->dateTime('from_at');
            $table->dateTime('until_at');

            // The earliest moment its held commissions become available.
            $table->dateTime('release_at');

            // open, calculated, finalized, released.
            $table->string('status', 16)->collation($exact);

            // When its calculation began: from then on no new business entry
            // may fall in its range, so every component calculates — and a
            // resumed calculation resumes — over the same input.
            $table->dateTime('input_closed_at')->nullable();

            $table->dateTime('calculated_at')->nullable();
            $table->dateTime('finalized_at')->nullable();
            $table->dateTime('released_at')->nullable();

            $table->timestamps();

            $table->unique(['program_id', 'idempotency_key']);
            $table->index(['program_id', 'from_at', 'until_at'], self::TIMELINE);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_commission_periods');
    }
};
