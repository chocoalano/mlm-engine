<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PandaBear\Mlm\Support\PandaMlmConfig;

/**
 * "Members below X, down to depth N" — a network volume with `max_depth`
 * (ADR-013) — reads only the paths within that depth, instead of X's whole
 * subtree to discard everything deeper.
 */
return new class extends Migration
{
    /**
     * Named here, as the index 000009 adds is: a stable name, whatever the
     * table prefix.
     */
    private const DESCENDANTS_BY_DEPTH = 'mlm_genealogy_paths_descendants_by_depth_index';

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
        Schema::table('mlm_genealogy_paths', function (Blueprint $table): void {
            $table->index(['tree_type', 'ancestor_id', 'depth'], self::DESCENDANTS_BY_DEPTH);
        });
    }

    public function down(): void
    {
        Schema::table('mlm_genealogy_paths', function (Blueprint $table): void {
            $table->dropIndex(self::DESCENDANTS_BY_DEPTH);
        });
    }
};
