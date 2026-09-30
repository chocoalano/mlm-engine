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
     * Named here: generated names are longer than MySQL allows.
     */
    private const SETTLEMENT = 'mlm_payout_requests_settlement_reference_unique';

    private const RESERVATION = 'mlm_payout_requests_reservation_unique';

    private const REFUND = 'mlm_payout_requests_refund_unique';

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

        // A request to pay part of a member's wallet out (ADR-030): its facts
        // never change; its status and the moments it moved do, and the
        // ledger transactions that reserved — and, on failure, returned — its
        // amount. The wallet's ledger balance is what it spends, never a
        // commission: no payout is traced to one.
        Schema::create('mlm_payout_requests', static function (Blueprint $table) use ($exact): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('program_id')->constrained('mlm_programs')->restrictOnDelete();
            $table->foreignUlid('member_id')->constrained('mlm_members')->restrictOnDelete();
            $table->foreignUlid('wallet_id')->constrained('mlm_wallets')->restrictOnDelete();

            // The program's system account the reserved amount moves to.
            $table->foreignUlid('settlement_ledger_account_id')->constrained('mlm_ledger_accounts')->restrictOnDelete();

            $table->string('currency', 3)->collation($exact);

            // Strictly positive, one ledger posting at most.
            $table->bigInteger('amount_millionths');

            // Where it goes, as the application names it: never credentials.
            $table->string('destination_type', 64)->collation($exact);
            $table->string('destination_reference', 191)->collation($exact);

            $table->string('idempotency_key', 191)->collation($exact);

            // requested, approved, processing, settled, failed, cancelled.
            $table->string('status', 16)->collation($exact);

            $table->dateTime('requested_at');
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('processing_at')->nullable();
            $table->dateTime('settled_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();

            // The external settlement's own reference, once settled.
            $table->string('settlement_reference', 191)->nullable()->collation($exact);

            // Why it failed, or was cancelled.
            $table->string('failure_reason', 255)->nullable();

            $table->foreignUlid('reservation_ledger_transaction_id')->nullable()->unique(self::RESERVATION)->constrained('mlm_ledger_transactions')->restrictOnDelete();
            $table->foreignUlid('refund_ledger_transaction_id')->nullable()->unique(self::REFUND)->constrained('mlm_ledger_transactions')->restrictOnDelete();

            $table->timestamps();

            $table->unique(['program_id', 'idempotency_key']);
            // One external settlement settles one request of a program. Every
            // supported database lets any number of rows leave it null.
            $table->unique(['program_id', 'settlement_reference'], self::SETTLEMENT);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_payout_requests');
    }
};
