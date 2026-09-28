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
        Schema::create('mlm_plan_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Restrict: a plan's version history cannot disappear with it.
            $table->foreignUlid('plan_id')->constrained('mlm_plans')->restrictOnDelete();

            $table->unsignedInteger('version');

            // The lifecycle's source of truth. No active flag and no
            // active_version_id on the plan: one state, in one place.
            $table->string('status', 20)->default('draft');

            $table->dateTime('validated_at')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('activated_at')->nullable();
            $table->dateTime('superseded_at')->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_plan_versions');
    }
};
