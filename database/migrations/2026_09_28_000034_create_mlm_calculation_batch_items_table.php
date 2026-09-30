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
    private const COMPONENT = 'mlm_calculation_batch_items_component_unique';

    private const POSITION = 'mlm_calculation_batch_items_position_unique';

    private const CHILD_KEY = 'mlm_calculation_batch_items_child_key_unique';

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

        // One commission component of a hybrid batch (ADR-028), in its place
        // in the batch's order, with the key its own run is calculated under
        // and, once calculated, that run. Written with the batch; only the
        // run is linked later, once.
        Schema::create('mlm_calculation_batch_items', static function (Blueprint $table) use ($exact): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('calculation_batch_id')->constrained('mlm_calculation_batches')->restrictOnDelete();
            $table->foreignUlid('plan_component_id')->constrained('mlm_plan_components')->restrictOnDelete();

            // 1, 2, 3…: by the component's position, then its id.
            $table->unsignedInteger('position');

            // The component's run is calculated under this key, and replays
            // under it: derived from the batch and the component, never given.
            $table->string('child_idempotency_key', 191)->collation($exact);

            $table->foreignUlid('calculation_run_id')->nullable()->unique()->constrained('mlm_calculation_runs')->restrictOnDelete();

            $table->timestamps();

            $table->unique(['calculation_batch_id', 'plan_component_id'], self::COMPONENT);
            $table->unique(['calculation_batch_id', 'position'], self::POSITION);
            $table->unique(['calculation_batch_id', 'child_idempotency_key'], self::CHILD_KEY);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_calculation_batch_items');
    }
};
