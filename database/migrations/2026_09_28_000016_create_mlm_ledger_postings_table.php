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
        // One signed amount per account per transaction; a transaction's
        // postings sum to exactly zero. Immutable, like their transaction.
        Schema::create('mlm_ledger_postings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('ledger_transaction_id')->constrained('mlm_ledger_transactions')->restrictOnDelete();
            $table->foreignUlid('ledger_account_id')->constrained('mlm_ledger_accounts')->restrictOnDelete();

            // Millionths, as an integer: exact on every database. Positive
            // raises the account's balance, negative lowers it.
            $table->bigInteger('amount_millionths');

            $table->timestamps();

            // Named explicitly: the generated name is longer than MySQL's
            // 64 characters, and PostgreSQL would silently cut it at 63.
            $table->unique(['ledger_transaction_id', 'ledger_account_id'], 'mlm_ledger_postings_transaction_account_unique');

            // An account's postings: its balance.
            $table->index('ledger_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_ledger_postings');
    }
};
