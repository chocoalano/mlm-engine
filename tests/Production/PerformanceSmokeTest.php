<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Production;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Finance\LedgerBalanceReader;
use PandaBear\Mlm\Metrics\MetricContext;
use PandaBear\Mlm\Metrics\MetricEngine;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\Wallet;
use PandaBear\Mlm\Panel\Support\GenealogyTree;
use PandaBear\Mlm\Tests\DatabaseTestCase;
use PandaBear\Mlm\Tests\Panel\Concerns\BuildsOperations;

/**
 * A modest-size sanity check (docs/production-readiness.md), opt-in:
 * MLM_PERFORMANCE_SMOKE=1. About 2,000 members, 5,000 volume entries and
 * 2,000 ledger postings, built through the services; the reads that scale
 * with a network must not scale their query count with it.
 *
 * Not a benchmark: the timings it prints are for a person reading them.
 */
final class PerformanceSmokeTest extends DatabaseTestCase
{
    use BuildsOperations;

    private const MEMBERS = 2_000;

    public function test_network_reads_stay_set_based_at_a_modest_size(): void
    {
        if (getenv('MLM_PERFORMANCE_SMOKE') !== '1') {
            $this->markTestSkipped('Opt-in: set MLM_PERFORMANCE_SMOKE=1.');
        }

        $timings = [];
        $started = microtime(true);
        $this->operatingProgram();
        $members = [$this->team['ALICE']];

        // A ten-wide sponsor tree, four levels deep under ALICE.
        $this->travelTo(CarbonImmutable::parse('2026-01-01 00:00:00'));

        for ($i = 1; $i < self::MEMBERS; $i++) {
            $members[$i] = Member::factory()->for($this->program)->create(['member_code' => sprintf('M%05d', $i), 'joined_at' => '2025-12-01 00:00:00']);
            $this->genealogy()->assignSponsor($members[$i], $members[intdiv($i - 1, 10)]);
        }

        $this->travelBack();
        $timings['members + sponsor tree'] = microtime(true) - $started;

        $started = microtime(true);

        for ($i = 0; $i < 5_000; $i++) {
            $this->sale($members[1 + ($i % (self::MEMBERS - 1))], '150', sprintf('2026-01-%02d', 1 + ($i % 28)), "order:{$i}");
        }

        $timings['5,000 volume entries'] = microtime(true) - $started;

        $started = microtime(true);

        for ($i = 1; $i <= 1_000; $i++) {
            $this->fundedWallet($members[$i], '10', "fund:{$i}");
        }

        $timings['1,000 funded wallets (2,000 postings)'] = microtime(true) - $started;

        $reads = [
            'sponsor network volume of the root' => fn () => $this->app->make(MetricEngine::class)->resolve('sponsor.network.volume', new MetricContext($members[0], ['type' => 'sales'], CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-02-01'))),
            'balances of 1,000 wallets' => fn () => $this->app->make(LedgerBalanceReader::class)->forWallets(Wallet::query()->get()),
            'explorer: 5 levels, capped at 500' => fn () => GenealogyTree::read($members[0], 'sponsor', 5),
            'direct sponsor calculation of a month' => fn () => $this->monthly($this->fixedComponent('direct-sponsor.fixed', $this->directParameters(), Plan::factory()->for($this->program)->create()), '2026-01'),
        ];

        $queries = [];

        foreach ($reads as $name => $read) {
            DB::connection()->flushQueryLog();
            DB::connection()->enableQueryLog();
            $started = microtime(true);
            $read();
            $timings[$name] = microtime(true) - $started;
            $queries[$name] = count(DB::connection()->getQueryLog());
            DB::connection()->disableQueryLog();
        }

        fwrite(STDERR, "\n".implode("\n", array_map(static fn (string $name, float $seconds): string => sprintf('%-45s %7.2fs%s', $name, $seconds, isset($queries[$name]) ? sprintf('  %d queries', $queries[$name]) : ''), array_keys($timings), $timings))."\n");

        $this->assertLessThanOrEqual(10, $queries['sponsor network volume of the root']);
        $this->assertLessThanOrEqual(10, $queries['balances of 1,000 wallets']);
        $this->assertLessThanOrEqual(12, $queries['explorer: 5 levels, capped at 500']);
        // Known, documented in docs/production-readiness.md: the direct
        // sponsor strategies look each entry's sponsor up on its own — one
        // query per eligible entry, linear rather than set-based. Guarded
        // here against growing worse than that.
        $this->assertLessThanOrEqual(5_000 + 100, $queries['direct sponsor calculation of a month'], 'The calculation reads more than once per entry.');
        $this->assertSame(5_000, Commission::query()->count());
    }
}
