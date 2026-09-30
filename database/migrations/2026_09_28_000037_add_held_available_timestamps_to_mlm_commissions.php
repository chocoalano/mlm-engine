<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PandaBear\Mlm\Support\PandaMlmConfig;

return new class extends Migration
{
    private const COLUMNS = ['held_at', 'available_at'];

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
        // When a period's finalization held a commission, and when its
        // release made it available (ADR-029). Existing commissions keep
        // their status: none was held or made available before periods.
        // MySQL cannot roll a failed run's schema changes back, so a rerun
        // finds the columns there.
        Schema::table('mlm_commissions', static function (Blueprint $table): void {
            foreach (self::COLUMNS as $column) {
                if (! Schema::hasColumn('mlm_commissions', $column)) {
                    $table->dateTime($column)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('mlm_commissions', static function (Blueprint $table): void {
            $table->dropColumn(self::COLUMNS);
        });
    }
};
