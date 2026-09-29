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
        // How far one binary pairing component has calculated (ADR-023): its
        // state began at started_at and covers everything before through_at.
        // Its next run starts exactly at through_at.
        Schema::create('mlm_binary_pairing_cursors', static function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('program_id')->constrained('mlm_programs')->restrictOnDelete();

            // One per component: a component of a new plan version has its own.
            $table->foreignUlid('plan_component_id')->unique()->constrained('mlm_plan_components')->restrictOnDelete();

            $table->dateTime('started_at');
            $table->dateTime('through_at');

            $table->foreignUlid('last_calculation_run_id')->constrained('mlm_calculation_runs')->restrictOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_binary_pairing_cursors');
    }
};
