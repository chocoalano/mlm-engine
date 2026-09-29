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
     * The index commission strategies find their source entries by: a
     * program's original entries of one type and source type, in an effective
     * range (ADR-019). Without it, MySQL and PostgreSQL read those entries by
     * walking the primary key through the whole table.
     */
    private const INDEX = 'mlm_volume_entries_program_type_source_effective_index';

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
        Schema::table('mlm_volume_entries', static function (Blueprint $table): void {
            $table->index(['program_id', 'type', 'source_type', 'effective_at'], self::INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('mlm_volume_entries', static function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }
};
