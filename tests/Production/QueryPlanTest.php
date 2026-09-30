<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Production;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Models\PayoutBatch;
use PandaBear\Mlm\Models\Wallet;
use PandaBear\Mlm\Tests\DatabaseTestCase;
use PandaBear\Mlm\Tests\Panel\Concerns\BuildsOperations;

/**
 * The critical reads, as the database plans them (docs/production-
 * readiness.md): each can be answered from an index, on MySQL and
 * PostgreSQL. SQLite plans nothing worth asserting, so this runs only
 * against a real database.
 *
 * The question is whether an index exists that serves the query, not
 * whether it is cheaper yet on a table this small: PostgreSQL is asked with
 * sequential scans disabled, and MySQL for the keys it considered.
 */
final class QueryPlanTest extends DatabaseTestCase
{
    use BuildsOperations;

    public function test_the_critical_reads_are_served_by_an_index(): void
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            $this->markTestSkipped('Query plans are checked on MySQL and PostgreSQL only.');
        }

        $this->operatingProgram('B1', 'B2', 'B3', 'B4');
        $version = $this->activeVersion();

        foreach (['B1', 'B2', 'B3', 'B4'] as $index => $code) {
            $this->sale($this->team[$code], '150', '2026-01-1'.$index, "order:{$code}");
        }

        $this->openPeriod($version);
        $this->fundedWallet($this->team['ALICE'], '100', 'fund:ALICE');
        $this->settlementAccount($this->team['ALICE']);
        $this->payoutRequest($this->team['ALICE'], '10', 'payout:1');
        $batch = $this->payoutBatches()->create($this->program, 'IDR', 'batch:1');
        $account = DB::table('mlm_ledger_accounts')->where('wallet_id', Wallet::query()->where('member_id', $this->team['ALICE']->id)->value('id'))->value('id');
        $at = CarbonImmutable::parse('2026-06-01 00:00:00');

        $plans = [
            // SponsorGenealogy::descendantsAt(): an anchor's paths, to a depth, as of a moment.
            'genealogy descendants' => ['mlm_genealogy_paths', DB::table('mlm_genealogy_paths')
                ->where('tree_type', 'sponsor')->where('ancestor_id', $this->team['ALICE']->id)
                ->where('depth', '>', 0)->where('depth', '<=', 3)->where('effective_from', '<=', $at)],
            // VolumeTotals / the member and network metrics: a member's entries of a type in a range.
            'member volume in a range' => ['mlm_volume_entries', DB::table('mlm_volume_entries')
                ->where('member_id', $this->team['B1']->id)->where('type', 'sales')
                ->where('effective_at', '>=', '2026-01-01 00:00:00')->where('effective_at', '<', '2026-02-01 00:00:00')],
            // CommissionPeriodManager / the period guard: a program's periods overlapping a range.
            'period timeline' => ['mlm_commission_periods', DB::table('mlm_commission_periods')
                ->where('program_id', $this->program->id)->where('from_at', '<', '2026-02-01 00:00:00')->where('until_at', '>', '2026-01-01 00:00:00')],
            // LedgerBalanceReader / wallet history: an account's postings.
            'wallet ledger postings' => ['mlm_ledger_postings', DB::table('mlm_ledger_postings')->where('ledger_account_id', $account)],
            // PayoutBatchTotals and the batch detail: a batch's items.
            'payout batch items' => ['mlm_payout_batch_items', DB::table('mlm_payout_batch_items')->where('payout_batch_id', $batch->id)],
        ];

        foreach ($plans as $name => [$table, $query]) {
            $this->assertTrue($this->usesIndex($driver, $table, $query), "The {$name} read is not served by an index on {$driver}.");
        }

        $this->assertInstanceOf(PayoutBatch::class, $batch);
    }

    private function usesIndex(string $driver, string $table, Builder $query): bool
    {
        $sql = $query->toSql();
        $bindings = $query->getBindings();

        if ($driver === 'pgsql') {
            return DB::transaction(static function () use ($sql, $bindings): bool {
                DB::statement('SET LOCAL enable_seqscan = off');
                $plan = json_encode(DB::select('EXPLAIN (FORMAT JSON) '.$sql, $bindings));

                return is_string($plan) && str_contains($plan, 'Index');
            });
        }

        foreach (DB::select('EXPLAIN '.$sql, $bindings) as $row) {
            $row = (array) $row;

            // MySQL 9 explains as a tree by default; 8 as a table.
            if (isset($row['EXPLAIN'])) {
                return preg_match('/(Covering index |Index )(lookup|range scan|scan) on '.preg_quote($table, '/').' /', (string) $row['EXPLAIN']) === 1;
            }

            if (($row['table'] ?? null) === $table) {
                return ($row['key'] ?? null) !== null || ($row['possible_keys'] ?? null) !== null;
            }
        }

        return false;
    }
}
