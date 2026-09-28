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
        // Where a member is structurally placed — independent of who
        // sponsored it. Its paths live in mlm_genealogy_paths under
        // tree_type 'placement'.
        Schema::create('mlm_placement_edges', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // The placed member: at most one placement parent each.
            $table->foreignUlid('member_id')->unique()->constrained('mlm_members')->restrictOnDelete();

            // Its placement parent. Indexed for "who is placed directly
            // under this member".
            $table->foreignUlid('parent_id')->index()->constrained('mlm_members')->restrictOnDelete();

            // No position, slot or side: which child sits where is a
            // plan-specific question the generic layer cannot validate.
            $table->dateTime('placed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_placement_edges');
    }
};
