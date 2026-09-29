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
    private const IDENTITY = 'mlm_binary_pairing_restorations_identity_unique';

    private const CORRECTION = 'mlm_binary_pairing_restorations_correction_foreign';

    public function up(): void
    {
        // Identifiers compare exactly on every database. MySQL's default
        // collations ignore letter case, so there they are made binary;
        // SQLite and PostgreSQL already compare exactly.
        $exact = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true) ? 'utf8mb4_bin' : null;

        // What a correction gave back (ADR-025): so much of one historical
        // allocation on the opposite side of the undone pair, returned to
        // the carry lot it was drawn from. Written once, never changed.
        Schema::create('mlm_binary_pairing_restorations', static function (Blueprint $table) use ($exact): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('binary_pairing_correction_id')->constrained('mlm_binary_pairing_corrections', indexName: self::CORRECTION)->restrictOnDelete();
            $table->foreignUlid('restored_allocation_id')->index()->constrained('mlm_binary_pairing_allocations')->restrictOnDelete();
            $table->foreignUlid('binary_carry_lot_id')->index()->constrained('mlm_binary_carry_lots')->restrictOnDelete();

            $table->string('side', 5)->collation($exact);

            // Part of one allocation; always positive.
            $table->bigInteger('quantity_millionths');

            $table->timestamps();

            $table->unique(['binary_pairing_correction_id', 'restored_allocation_id'], self::IDENTITY);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_binary_pairing_restorations');
    }
};
