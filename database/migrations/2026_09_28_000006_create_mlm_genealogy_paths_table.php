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
        // A closure table: one row per ancestor/descendant pair, including
        // each member's path to itself at depth 0.
        Schema::create('mlm_genealogy_paths', function (Blueprint $table): void {
            // Which tree the path belongs to. Only `sponsor` exists; the
            // column lets a later network structure keep its own paths.
            $table->string('tree_type', 20);

            $table->foreignUlid('ancestor_id')->constrained('mlm_members')->restrictOnDelete();
            $table->foreignUlid('descendant_id')->constrained('mlm_members')->restrictOnDelete();
            $table->unsignedInteger('depth');

            // One path per pair and tree. Its prefix serves "descendants of".
            $table->primary(['tree_type', 'ancestor_id', 'descendant_id']);

            // "Ancestors of", nearest first.
            $table->index(['tree_type', 'descendant_id', 'depth']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_genealogy_paths');
    }
};
