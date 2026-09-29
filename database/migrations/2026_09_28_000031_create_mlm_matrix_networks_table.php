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
        // A program's matrix network (ADR-027): one per program, and the
        // width every matrix parent's numbered slots run to. Configured once,
        // never changed. Empty until a program is configured: no network is
        // guessed from existing placements.
        Schema::create('mlm_matrix_networks', static function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('program_id')->unique()->constrained('mlm_programs')->restrictOnDelete();

            // 1 to 100 slots per parent.
            $table->unsignedInteger('width');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_matrix_networks');
    }
};
