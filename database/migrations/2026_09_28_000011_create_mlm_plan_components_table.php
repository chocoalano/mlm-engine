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

        // One configuration unit of a plan version (ADR-014): relational
        // identity, and a JSON object of inert parameters for its driver.
        Schema::create('mlm_plan_components', function (Blueprint $table) use ($exact): void {
            $table->ulid('id')->primary();

            // Owned wholly by its version: only a draft can be deleted, and
            // its definition goes with it.
            $table->foreignUlid('plan_version_id')->constrained('mlm_plan_versions')->cascadeOnDelete();

            $table->string('key', 64)->collation($exact);

            // The registered driver's key — never a class name.
            $table->string('driver', 100)->collation($exact);

            $table->string('name');
            $table->json('parameters');

            // Display and trace order; ties fall back to the id.
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['plan_version_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_plan_components');
    }
};
