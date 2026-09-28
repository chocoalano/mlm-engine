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
        Schema::create('mlm_sponsor_edges', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // The sponsored member: at most one direct sponsor each.
            $table->foreignUlid('member_id')->unique()->constrained('mlm_members')->restrictOnDelete();

            // Indexed for "who did this sponsor sponsor directly".
            $table->foreignUlid('sponsor_id')->index()->constrained('mlm_members')->restrictOnDelete();

            $table->dateTime('assigned_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_sponsor_edges');
    }
};
