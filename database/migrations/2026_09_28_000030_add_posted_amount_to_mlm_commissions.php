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
        // What posting a commission actually moved to the wallet (ADR-026):
        // its net entitlement then — the calculated amount less the binary
        // corrections recorded before it was posted. The calculated amount
        // itself never changes. MySQL cannot roll a failed run's schema
        // changes back, so a rerun finds the column there.
        if (! Schema::hasColumn('mlm_commissions', 'posted_amount_millionths')) {
            Schema::table('mlm_commissions', static function (Blueprint $table): void {
                $table->bigInteger('posted_amount_millionths')->nullable();
            });
        }

        // Every commission posted before this migration was posted whole.
        $db = Schema::getConnection();
        $db->table('mlm_commissions')
            ->whereIn('status', ['posted', 'reversed'])
            ->whereNull('posted_amount_millionths')
            ->update(['posted_amount_millionths' => $db->raw('amount_millionths')]);
    }

    public function down(): void
    {
        Schema::table('mlm_commissions', static function (Blueprint $table): void {
            $table->dropColumn('posted_amount_millionths');
        });
    }
};
