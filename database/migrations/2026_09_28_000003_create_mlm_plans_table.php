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
        Schema::create('mlm_plans', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Restrict, as for members: a program with plans cannot be
            // deleted by accident.
            $table->foreignUlid('program_id')->constrained('mlm_programs')->restrictOnDelete();

            $table->string('code', 64);
            $table->string('name');
            $table->timestamps();

            $table->unique(['program_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_plans');
    }
};
