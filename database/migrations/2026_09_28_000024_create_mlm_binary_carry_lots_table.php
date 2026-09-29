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

    /**
     * Named here: generated names are longer than MySQL allows.
     */
    private const IDENTITY = 'mlm_binary_carry_lots_identity_unique';

    private const MEMBER_OPEN = 'mlm_binary_carry_lots_member_open_index';

    private const OPEN = 'mlm_binary_carry_lots_open_index';

    public function up(): void
    {
        // Identifiers compare exactly on every database. MySQL's default
        // collations ignore letter case, so there they are made binary;
        // SQLite and PostgreSQL already compare exactly.
        $exact = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true) ? 'utf8mb4_bin' : null;

        // Binary carry, by source (ADR-023): one original volume entry as it
        // fell in one side of one binary member's legs, for one pairing
        // component — how much it brought, and how much is still unpaired.
        Schema::create('mlm_binary_carry_lots', static function (Blueprint $table) use ($exact): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('program_id')->constrained('mlm_programs')->restrictOnDelete();
            $table->foreignUlid('plan_component_id')->constrained('mlm_plan_components')->restrictOnDelete();

            // The binary member whose carry this is, and the leg it fell in.
            $table->foreignUlid('member_id')->constrained('mlm_members')->restrictOnDelete();
            $table->string('side', 5)->collation($exact);

            // The original entry, and its moment: the order carry is paired in.
            $table->foreignUlid('source_volume_entry_id')->index()->constrained('mlm_volume_entries')->restrictOnDelete();
            $table->dateTime('source_effective_at');

            // One entry's quantity, so one BIGINT holds it; never negative.
            $table->bigInteger('quantity_millionths');
            $table->bigInteger('remaining_millionths');

            // The reversal that took back what remained unpaired.
            $table->foreignUlid('reversed_by_volume_entry_id')->nullable()->constrained('mlm_volume_entries')->restrictOnDelete();

            $table->timestamps();

            // An entry falls once into each binary member's carry.
            $table->unique(['plan_component_id', 'member_id', 'source_volume_entry_id'], self::IDENTITY);

            // Open carry — what a run reads — without the consumed history
            // behind it: one member's side, and the whole component's, the
            // latter covering what is read. Measured (ADR-023): without them,
            // every run walked past every lot ever consumed.
            $table->index(['plan_component_id', 'member_id', 'side', 'remaining_millionths'], self::MEMBER_OPEN);
            $table->index(['plan_component_id', 'remaining_millionths', 'member_id', 'side', 'program_id'], self::OPEN);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_binary_carry_lots');
    }
};
