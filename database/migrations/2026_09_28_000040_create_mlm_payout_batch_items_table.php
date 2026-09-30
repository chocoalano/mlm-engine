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
     * Named here: one batch's positions.
     */
    private const POSITION = 'mlm_payout_batch_items_position_unique';

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
        // A payout request's place in a batch (ADR-030): a request belongs to
        // one batch at most, in the order it was added. Never removed.
        Schema::create('mlm_payout_batch_items', static function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('payout_batch_id')->constrained('mlm_payout_batches')->restrictOnDelete();
            $table->foreignUlid('payout_request_id')->unique()->constrained('mlm_payout_requests')->restrictOnDelete();

            // 1, 2, 3…: the order requests were added in.
            $table->unsignedInteger('position');

            $table->timestamps();

            $table->unique(['payout_batch_id', 'position'], self::POSITION);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_payout_batch_items');
    }
};
