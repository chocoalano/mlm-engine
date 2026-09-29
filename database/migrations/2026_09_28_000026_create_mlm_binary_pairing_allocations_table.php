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
     * Named here: the generated name is longer than MySQL allows.
     */
    private const IDENTITY = 'mlm_binary_pairing_allocations_identity_unique';

    public function up(): void
    {
        // Identifiers compare exactly on every database. MySQL's default
        // collations ignore letter case, so there they are made binary;
        // SQLite and PostgreSQL already compare exactly.
        $exact = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true) ? 'utf8mb4_bin' : null;

        // Which carry a pairing consumed (ADR-023): so much of one source
        // entry's lot, by one pairing result. Written once, never changed.
        Schema::create('mlm_binary_pairing_allocations', static function (Blueprint $table) use ($exact): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('binary_pairing_result_id')->constrained('mlm_binary_pairing_results')->restrictOnDelete();

            // Indexed for "what consumed this lot", from the lot's side.
            $table->foreignUlid('binary_carry_lot_id')->index()->constrained('mlm_binary_carry_lots')->restrictOnDelete();

            $table->string('side', 5)->collation($exact);

            // At most one lot's quantity; always positive.
            $table->bigInteger('quantity_millionths');

            $table->timestamps();

            $table->unique(['binary_pairing_result_id', 'binary_carry_lot_id'], self::IDENTITY);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_binary_pairing_allocations');
    }
};
