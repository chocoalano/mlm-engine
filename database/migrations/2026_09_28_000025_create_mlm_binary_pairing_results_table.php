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
        // What one binary pairing run did for one binary member (ADR-023):
        // its carry before, what the run added and took back, what paired,
        // and its carry after — written once, never changed. Totals are
        // exact decimal text: they are sums, and may outgrow any integer
        // column.
        Schema::create('mlm_binary_pairing_results', static function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('calculation_run_id')->constrained('mlm_calculation_runs')->restrictOnDelete();
            $table->foreignUlid('program_id')->constrained('mlm_programs')->restrictOnDelete();
            $table->foreignUlid('plan_component_id')->constrained('mlm_plan_components')->restrictOnDelete();
            $table->foreignUlid('member_id')->constrained('mlm_members')->restrictOnDelete();

            foreach (['left_carry_before', 'right_carry_before', 'left_added', 'right_added', 'left_reversed', 'right_reversed', 'left_available', 'right_available', 'pair_quantity', 'pair_count', 'consumed_quantity', 'left_carry_after', 'right_carry_after'] as $column) {
                $table->text($column);
            }

            // The commission it earned; none when nothing paired, or when a
            // proportional award rounded to zero.
            $table->foreignUlid('commission_id')->nullable()->unique()->constrained('mlm_commissions')->restrictOnDelete();

            $table->timestamps();

            $table->unique(['calculation_run_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_binary_pairing_results');
    }
};
