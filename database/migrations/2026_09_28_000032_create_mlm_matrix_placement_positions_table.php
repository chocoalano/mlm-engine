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
     * Named here: one parent's numbered slots in its network.
     */
    private const SLOT = 'mlm_matrix_placement_positions_slot_unique';

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
        // The matrix overlay (ADR-027): a generic placement edge assigned to
        // one of its parent's numbered slots. Empty until edges are placed or
        // adopted explicitly — no existing placement is ever guessed into it.
        // Its paths live in mlm_genealogy_paths under tree_type 'matrix'.
        Schema::create('mlm_matrix_placement_positions', static function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('matrix_network_id')->constrained('mlm_matrix_networks')->restrictOnDelete();

            // The generic edge, enrolled once. The placed member is the edge's.
            $table->foreignUlid('placement_edge_id')->unique()->constrained('mlm_placement_edges')->restrictOnDelete();

            // The edge's parent, kept here for the slot key and a direct lookup
            // of a slot; always the edge's own parent.
            $table->foreignUlid('parent_id')->constrained('mlm_members')->restrictOnDelete();

            // 1 to the network's width.
            $table->unsignedInteger('slot');

            // When the edge entered the matrix: never before its generic
            // placement, and for an adopted edge not before its adoption.
            $table->dateTime('assigned_at');

            $table->timestamps();

            // One member per slot of a parent: the width's backstop.
            $table->unique(['matrix_network_id', 'parent_id', 'slot'], self::SLOT);
        });
    }

    public function down(): void
    {
        // The matrix paths go with the positions they were built from: left
        // behind, they would describe a tree nothing records.
        if (Schema::hasTable('mlm_genealogy_paths')) {
            Schema::getConnection()->table('mlm_genealogy_paths')->where('tree_type', 'matrix')->delete();
        }

        Schema::dropIfExists('mlm_matrix_placement_positions');
    }
};
