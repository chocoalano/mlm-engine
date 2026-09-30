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
        $exact = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true) ? 'utf8mb4_bin' : null;

        // An operational grouping of one program's payout requests in one
        // currency (ADR-030). It owns no money: each request keeps its own
        // reservation and refund.
        Schema::create('mlm_payout_batches', static function (Blueprint $table) use ($exact): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('program_id')->constrained('mlm_programs')->restrictOnDelete();
            $table->string('currency', 3)->collation($exact);
            $table->string('idempotency_key', 191)->collation($exact);

            // open, sealed, processing, completed, cancelled.
            $table->string('status', 16)->collation($exact);

            $table->dateTime('sealed_at')->nullable();
            $table->dateTime('processing_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();

            $table->timestamps();

            $table->unique(['program_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_payout_batches');
    }
};
