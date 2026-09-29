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

        // A member's money in one currency. No balance column, ever: a
        // balance is the sum of the wallet account's postings.
        Schema::create('mlm_wallets', function (Blueprint $table) use ($exact): void {
            $table->ulid('id')->primary();

            // Denormalised from the member, as the wallet manager writes it:
            // an explicit program boundary for program-scoped keys.
            $table->foreignUlid('program_id')->constrained('mlm_programs')->restrictOnDelete();
            $table->foreignUlid('member_id')->constrained('mlm_members')->restrictOnDelete();

            $table->string('currency', 3)->collation($exact);

            $table->timestamps();

            // One wallet per member and currency.
            $table->unique(['program_id', 'member_id', 'currency']);

            // A member's wallets.
            $table->index('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlm_wallets');
    }
};
