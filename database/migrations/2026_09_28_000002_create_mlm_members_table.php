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
        Schema::create('mlm_members', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Restrict, not cascade: a program with members cannot be deleted
            // by accident, and deletion policy is decided once the domains
            // that depend on members exist.
            $table->foreignUlid('program_id')->constrained('mlm_programs')->restrictOnDelete();

            $table->string('member_code', 64);
            $table->string('external_type', 64)->nullable();
            $table->string('external_id', 128)->nullable();
            $table->dateTime('joined_at');
            $table->timestamps();

            $table->unique(['program_id', 'member_code']);

            // NULLs are distinct in SQLite, MySQL and PostgreSQL unique
            // indexes, so members without an external identity never collide.
            // A half-set identity is refused by the model before it gets here.
            $table->unique(['program_id', 'external_type', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_members');
    }
};
