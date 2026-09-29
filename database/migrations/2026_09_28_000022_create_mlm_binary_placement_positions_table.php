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
     * Named here: one parent's left and right slots.
     */
    private const SLOT = 'mlm_binary_placement_positions_parent_side_unique';

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
        // Identifiers compare exactly on every database. MySQL's default
        // collations ignore letter case, so there they are made binary;
        // SQLite and PostgreSQL already compare exactly.
        $exact = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true) ? 'utf8mb4_bin' : null;

        // The binary overlay (ADR-022): a generic placement edge assigned to
        // its parent's left or right. Empty until edges are placed or adopted
        // explicitly — no existing placement is ever guessed into it. Its
        // paths live in mlm_genealogy_paths under tree_type 'binary'.
        Schema::create('mlm_binary_placement_positions', static function (Blueprint $table) use ($exact): void {
            $table->ulid('id')->primary();

            // The generic edge, enrolled once. The placed member is the edge's.
            $table->foreignUlid('placement_edge_id')->unique()->constrained('mlm_placement_edges')->restrictOnDelete();

            // The edge's parent, kept here for the slot key and a direct lookup
            // of a side; always the edge's own parent.
            $table->foreignUlid('parent_id')->constrained('mlm_members')->restrictOnDelete();

            // `left` or `right`, spelled exactly.
            $table->string('side', 5)->collation($exact);

            // When the edge entered the binary tree: never before its generic
            // placement, and for an adopted edge not before its adoption.
            $table->dateTime('assigned_at');

            $table->timestamps();

            // One left and one right per parent. Its prefix serves the parent's
            // foreign key and "the child on this side".
            $table->unique(['parent_id', 'side'], self::SLOT);
        });
    }

    public function down(): void
    {
        // The binary paths go with the positions they were built from: left
        // behind, they would describe a tree nothing records.
        if (Schema::hasTable('mlm_genealogy_paths')) {
            Schema::getConnection()->table('mlm_genealogy_paths')->where('tree_type', 'binary')->delete();
        }

        Schema::dropIfExists('mlm_binary_placement_positions');
    }
};
