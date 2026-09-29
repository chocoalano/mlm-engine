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
        // Identifiers compare exactly on every database. MySQL's default
        // collations ignore letter case, so there they are made binary;
        // SQLite and PostgreSQL already compare exactly.
        $exact = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true) ? 'utf8mb4_bin' : null;

        // Where postings land: a wallet's account, or a system account the
        // program opens under a key of its own. No balance column, ever.
        Schema::create('mlm_ledger_accounts', function (Blueprint $table) use ($exact): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('program_id')->constrained('mlm_programs')->restrictOnDelete();

            // Set for a wallet's account, null for a system account; a wallet
            // has at most one account.
            $table->foreignUlid('wallet_id')->nullable()->unique()->constrained('mlm_wallets')->restrictOnDelete();

            $table->string('currency', 3)->collation($exact);
            $table->string('key', 100)->collation($exact);

            $table->timestamps();

            $table->unique(['program_id', 'currency', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_ledger_accounts');
    }
};
