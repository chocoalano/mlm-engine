<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use PandaBear\Mlm\Support\PandaMlmConfig;

return new class extends Migration
{
    /**
     * The package's own strategies whose commissions were earned from one
     * original volume entry each, named in their trace (ADR-019, ADR-020).
     * Other strategies' commissions are left without provenance: nothing is
     * guessed from their traces.
     */
    private const STRATEGIES = ['direct-sponsor.fixed', 'direct-sponsor.proportional', 'unilevel.fixed', 'unilevel.proportional'];

    /**
     * Commissions by the record they were earned from, whatever their
     * program — so a lookup can also find provenance that crosses programs,
     * and refuse it — and within one program.
     */
    private const INDEX = 'mlm_commissions_source_index';

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
        $db = Schema::getConnection();
        $exact = in_array($db->getDriverName(), ['mysql', 'mariadb'], true) ? 'utf8mb4_bin' : null;

        // MySQL cannot roll a failed run's schema changes back, so a rerun
        // finds the columns there.
        if (! Schema::hasColumn('mlm_commissions', 'source_type')) {
            Schema::table('mlm_commissions', static function (Blueprint $table) use ($exact): void {
                // Both set, or both null.
                $table->string('source_type', 64)->nullable()->collation($exact);
                $table->string('source_id', 191)->nullable()->collation($exact);
            });
        }

        // All of the backfill or none of it: a commission of these strategies
        // left without provenance could never be clawed back.
        $db->transaction(fn () => $this->backfill($db));

        if (! in_array(self::INDEX, array_column(Schema::getIndexes('mlm_commissions'), 'name'), true)) {
            Schema::table('mlm_commissions', static function (Blueprint $table): void {
                $table->index(['source_type', 'source_id', 'program_id'], self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if (in_array(self::INDEX, array_column(Schema::getIndexes('mlm_commissions'), 'name'), true)) {
            Schema::table('mlm_commissions', static function (Blueprint $table): void {
                $table->dropIndex(self::INDEX);
            });
        }

        Schema::table('mlm_commissions', static function (Blueprint $table): void {
            $table->dropColumn(['source_type', 'source_id']);
        });
    }

    /**
     * Each commission of the package's source-entry strategies takes the
     * original volume entry its trace names — which must exist, belong to
     * the commission's program and be an original, or the migration stops.
     * Rows already backfilled are skipped, so a rerun completes the work.
     */
    private function backfill(Connection $db): void
    {
        $db->table('mlm_commissions')
            ->join('mlm_calculation_runs', 'mlm_calculation_runs.id', '=', 'mlm_commissions.calculation_run_id')
            ->whereIn('mlm_calculation_runs.strategy', self::STRATEGIES)
            ->whereNull('mlm_commissions.source_type')
            ->select(['mlm_commissions.id', 'mlm_commissions.program_id', 'mlm_commissions.trace'])
            ->chunkById(500, function (Collection $commissions) use ($db): void {
                $sources = [];

                foreach ($commissions as $commission) {
                    $sources[$commission->id] = $this->sourceOf($commission);
                }

                $entries = $db->table('mlm_volume_entries')
                    ->whereIn('id', array_values(array_unique($sources)))
                    ->get(['id', 'program_id', 'reversal_of_id'])
                    ->keyBy('id');

                foreach ($commissions as $commission) {
                    $entry = $entries->get($sources[$commission->id]);

                    if ($entry === null) {
                        throw $this->refused($commission->id, "its trace names volume entry [{$sources[$commission->id]}], which does not exist");
                    }

                    if ($entry->program_id !== $commission->program_id) {
                        throw $this->refused($commission->id, "its trace names volume entry [{$entry->id}] of program [{$entry->program_id}], not the commission's program [{$commission->program_id}]");
                    }

                    if ($entry->reversal_of_id !== null) {
                        throw $this->refused($commission->id, "its trace names volume entry [{$entry->id}], which is a reversal, not an original entry");
                    }

                    $db->table('mlm_commissions')->where('id', $commission->id)->update([
                        'source_type' => 'volume-entry',
                        'source_id' => $entry->id,
                    ]);
                }
            }, 'mlm_commissions.id', 'id');
    }

    private function sourceOf(object $commission): string
    {
        try {
            $trace = json_decode((string) $commission->trace, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->refused($commission->id, 'its trace is not JSON');
        }

        $id = is_array($trace) && is_array($trace['source'] ?? null) ? ($trace['source']['volume_entry_id'] ?? null) : null;

        if (! is_string($id) || $id === '' || trim($id) !== $id || strlen($id) > 191 || preg_match('/[\x00-\x1F\x7F]/', $id) === 1) {
            throw $this->refused($commission->id, 'its trace names no valid source.volume_entry_id');
        }

        return $id;
    }

    private function refused(string $commission, string $reason): RuntimeException
    {
        return new RuntimeException("Commission [{$commission}] cannot be given its source provenance: {$reason}. No provenance was written; correct the row and migrate again.");
    }
};
