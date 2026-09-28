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
        // Identifiers compare exactly on every database (ADR-002).
        $exact = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true) ? 'utf8mb4_bin' : null;

        // One named condition tree of a component (ADR-014): relational
        // identity, and the tree itself as JSON in the safe rule language.
        Schema::create('mlm_plan_rules', function (Blueprint $table) use ($exact): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('plan_component_id')->constrained('mlm_plan_components')->cascadeOnDelete();

            $table->string('key', 64)->collation($exact);
            $table->string('name');
            $table->json('definition');

            // Display and trace order; ties fall back to the id.
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['plan_component_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_plan_rules');
    }
};
