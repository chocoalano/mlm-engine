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
     * Named here: generated names are longer than MySQL allows.
     */
    private const COMPONENT = 'mlm_commission_period_runs_component_unique';

    private const POSITION = 'mlm_commission_period_runs_position_unique';

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
        // Which run calculated each commission component of a period
        // (ADR-029), in the period's order: the relational provenance of every
        // commission a period holds. Written once per component, when its run
        // is linked; never changed.
        Schema::create('mlm_commission_period_runs', static function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('commission_period_id')->constrained('mlm_commission_periods')->restrictOnDelete();
            $table->foreignUlid('plan_component_id')->constrained('mlm_plan_components')->restrictOnDelete();
            $table->foreignUlid('calculation_run_id')->unique()->constrained('mlm_calculation_runs')->restrictOnDelete();

            // 1, 2, 3…: by the component's position, then its id.
            $table->unsignedInteger('position');

            $table->timestamps();

            $table->unique(['commission_period_id', 'plan_component_id'], self::COMPONENT);
            $table->unique(['commission_period_id', 'position'], self::POSITION);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_commission_period_runs');
    }
};
