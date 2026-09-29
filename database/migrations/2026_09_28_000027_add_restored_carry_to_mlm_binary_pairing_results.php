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

    private const COLUMNS = ['left_restored', 'right_restored'];

    public function up(): void
    {
        // What corrections of earlier pairs gave back to each side's carry in
        // the run (ADR-025). Exact decimal text, like every other total; no
        // result before corrections existed restored anything.
        Schema::table('mlm_binary_pairing_results', static function (Blueprint $table): void {
            foreach (self::COLUMNS as $column) {
                if (! Schema::hasColumn('mlm_binary_pairing_results', $column)) {
                    $table->text($column)->nullable();
                }
            }
        });

        Schema::getConnection()->table('mlm_binary_pairing_results')->update(['left_restored' => '0', 'right_restored' => '0']);

        Schema::table('mlm_binary_pairing_results', static function (Blueprint $table): void {
            foreach (self::COLUMNS as $column) {
                $table->text($column)->nullable(false)->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('mlm_binary_pairing_results', static function (Blueprint $table): void {
            $table->dropColumn(self::COLUMNS);
        });
    }
};
