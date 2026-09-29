<?php

declare(strict_types=1);

/*
 * One package operation in a process — and a database session — of its own,
 * for the real-database concurrency tests. Not part of the package.
 *
 *   php worker.php '{"engine":"mysql","op":"sponsor",...}'
 *
 * Boots a Laravel application through Testbench with this package's service
 * provider, pointed at the same MLM_TEST_* database as the test run, calls
 * one public service, and prints one JSON line:
 *
 *   {"ok":true,"id":"..."}  or  {"ok":false,"exception":"...","message":"..."}
 *
 * The "hold" operation writes a volume entry in an open transaction, prints
 * {"event":"held"}, and commits only once another session is waiting on it.
 * "hold_rows" does the same with rows of any tables, in the order given.
 */

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\Foundation\Application;
use PandaBear\Mlm\Binary\BinaryPlacementManager;
use PandaBear\Mlm\Binary\BinarySide;
use PandaBear\Mlm\Calculation\CalculationContext;
use PandaBear\Mlm\Calculation\CalculationEngine;
use PandaBear\Mlm\Commission\CommissionAdjustmentEngine;
use PandaBear\Mlm\Commission\CommissionPoster;
use PandaBear\Mlm\Commission\CommissionStrategyRegistry;
use PandaBear\Mlm\Finance\LedgerPostingInput;
use PandaBear\Mlm\Finance\LedgerRecorder;
use PandaBear\Mlm\Finance\PostLedgerTransaction;
use PandaBear\Mlm\Finance\ReverseLedgerTransaction;
use PandaBear\Mlm\Finance\WalletManager;
use PandaBear\Mlm\Genealogy\PlacementGenealogy;
use PandaBear\Mlm\Genealogy\SponsorGenealogy;
use PandaBear\Mlm\Metrics\MetricEngine;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\LedgerTransaction;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\PlacementEdge;
use PandaBear\Mlm\Models\PlanComponent;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Models\VolumeEntry;
use PandaBear\Mlm\PandaMlmServiceProvider;
use PandaBear\Mlm\Planning\PlanDefinitionEditor;
use PandaBear\Mlm\Planning\PlanVersionLifecycle;
use PandaBear\Mlm\Tests\Database\ExternalDatabase;
use PandaBear\Mlm\Tests\Fixtures\FixedCommissionStrategy;
use PandaBear\Mlm\Tests\Fixtures\SnapshotProbeStrategy;
use PandaBear\Mlm\Volume\Quantity;
use PandaBear\Mlm\Volume\RecordVolume;
use PandaBear\Mlm\Volume\ReverseVolume;
use PandaBear\Mlm\Volume\VolumeRecorder;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$emit = static function (array $line): void {
    fwrite(STDOUT, json_encode($line, JSON_THROW_ON_ERROR).PHP_EOL);
    fflush(STDOUT);
};

try {
    /** @var array<string, mixed> $job */
    $job = json_decode($argv[1] ?? '', true, flags: JSON_THROW_ON_ERROR);
    $database = ExternalDatabase::for($job['engine']);

    $app = Application::create(
        options: ['extra' => ['providers' => [PandaMlmServiceProvider::class], 'dont-discover' => ['*']]],
    );

    // Before the first query: connections, and the package's connection
    // setting, are both read lazily.
    $config = $app->make('config');
    $config->set("database.connections.{$database->connection}", $database->config());
    $config->set('database.default', $database->connection);
    $config->set('mlm.database.connection', $database->connection);

    DB::statement($database->lockTimeoutStatement(20));

    $record = static fn (array $job): RecordVolume => new RecordVolume(
        member: Member::findOrFail($job['member']),
        type: $job['type'],
        quantity: Quantity::of($job['quantity']),
        sourceType: $job['source_type'],
        sourceId: $job['source_id'],
        idempotencyKey: $job['key'],
        effectiveAt: CarbonImmutable::parse($job['effective_at']),
    );

    // Commits only once another session is blocked on what it wrote.
    $holdUntilWaitedOn = static function (Closure $write) use ($database, $emit): void {
        DB::beginTransaction();
        $write();
        $emit(['event' => 'held']);

        $deadline = microtime(true) + 20;
        while (DB::select($database->waitingStatementsQuery()) === []) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('No session came to wait on the held rows.');
            }
            usleep(20_000);
        }

        DB::commit();
    };

    $calculate = static fn (array $job): string => app(CalculationEngine::class)->calculate(
        PlanComponent::findOrFail($job['component']),
        new CalculationContext(CarbonImmutable::parse($job['from']), CarbonImmutable::parse($job['until']), $job['key']),
    )->getKey();

    $id = match ($job['op']) {
        'sponsor' => app(SponsorGenealogy::class)
            ->assignSponsor(Member::findOrFail($job['member']), Member::findOrFail($job['sponsor']))->getKey(),
        'place' => app(PlacementGenealogy::class)
            ->place(Member::findOrFail($job['member']), Member::findOrFail($job['parent']))->getKey(),
        'binary_place' => app(BinaryPlacementManager::class)
            ->place(Member::findOrFail($job['member']), Member::findOrFail($job['parent']), BinarySide::from($job['side']))->getKey(),
        'binary_adopt' => app(BinaryPlacementManager::class)
            ->adopt(PlacementEdge::findOrFail($job['edge']), BinarySide::from($job['side']))->getKey(),
        'record' => app(VolumeRecorder::class)->record($record($job))->getKey(),
        'reverse' => app(VolumeRecorder::class)->reverse(new ReverseVolume(
            entry: VolumeEntry::findOrFail($job['entry']),
            sourceType: $job['source_type'],
            sourceId: $job['source_id'],
            idempotencyKey: $job['key'],
            effectiveAt: CarbonImmutable::parse($job['effective_at']),
        ))->getKey(),
        'plan_add_component' => app(PlanDefinitionEditor::class)
            ->addComponent(PlanVersion::findOrFail($job['version']), $job['key'], $job['driver'], $job['name'])->getKey(),
        'plan_validate' => app(PlanVersionLifecycle::class)->markValidated(PlanVersion::findOrFail($job['version']))->getKey(),
        'wallet_open' => app(WalletManager::class)->open(Member::findOrFail($job['member']), $job['currency'])->getKey(),
        'ledger_post' => app(LedgerRecorder::class)->post(new PostLedgerTransaction(
            program: Program::findOrFail($job['program']),
            currency: $job['currency'],
            type: $job['type'],
            sourceType: $job['source_type'],
            sourceId: $job['source_id'],
            idempotencyKey: $job['key'],
            occurredAt: CarbonImmutable::parse($job['occurred_at']),
            postings: array_map(
                static fn (array $line): LedgerPostingInput => LedgerPostingInput::of(LedgerAccount::findOrFail($line[0]), $line[1]),
                $job['postings'],
            ),
        ))->getKey(),
        'ledger_reverse' => app(LedgerRecorder::class)->reverse(new ReverseLedgerTransaction(
            transaction: LedgerTransaction::findOrFail($job['transaction']),
            sourceType: $job['source_type'],
            sourceId: $job['source_id'],
            idempotencyKey: $job['key'],
            occurredAt: CarbonImmutable::parse($job['occurred_at']),
        ))->getKey(),
        'calculate' => (static function () use ($job, $calculate): string {
            app(CommissionStrategyRegistry::class)->register(new FixedCommissionStrategy);

            return $calculate($job);
        })(),
        // Reads, reports "first-read", waits for the test to commit changes
        // from its own session, and reads again — under a session whose
        // default isolation is READ COMMITTED, as an application may set it.
        'calculate_snapshot' => (static function () use ($job, $calculate, $database, $emit): string {
            DB::statement($database->engine === 'mysql'
                ? 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED'
                : 'SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL READ COMMITTED');

            app(CommissionStrategyRegistry::class)->register(new SnapshotProbeStrategy(app(MetricEngine::class), static function () use ($job, $emit): void {
                $emit(['event' => 'first-read']);

                $deadline = microtime(true) + 20;
                while (! file_exists($job['signal'])) {
                    if (microtime(true) > $deadline) {
                        throw new RuntimeException('The test never committed its changes.');
                    }
                    usleep(20_000);
                }
            }));

            return $calculate($job);
        })(),
        'clawback' => (string) app(CommissionAdjustmentEngine::class)->processVolumeReversal(VolumeEntry::findOrFail($job['reversal']))->count(),
        'binary_clawback' => (string) app(CommissionAdjustmentEngine::class)->processBinaryReversal(VolumeEntry::findOrFail($job['reversal']))->count(),
        'commission_post' => app(CommissionPoster::class)->post(Commission::findOrFail($job['commission']))->getKey(),
        'commission_reverse' => app(CommissionPoster::class)->reverse(Commission::findOrFail($job['commission']), CarbonImmutable::parse($job['occurred_at']))->getKey(),
        'hold_rows' => (static function () use ($job, $holdUntilWaitedOn): string {
            $holdUntilWaitedOn(static function () use ($job): void {
                foreach ($job['rows'] as [$table, $row]) {
                    DB::table($table)->insert($row);
                }
            });

            return $job['rows'][0][1]['id'];
        })(),
        'hold' => (static function () use ($job, $database, $emit): string {
            DB::beginTransaction();
            DB::table('mlm_volume_entries')->insert($job['row']);
            $emit(['event' => 'held']);

            // Commit only once another session is blocked on this row.
            $deadline = microtime(true) + 20;
            while (DB::select($database->waitingStatementsQuery()) === []) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('No session came to wait on the held row.');
                }
                usleep(20_000);
            }

            DB::commit();

            return $job['row']['id'];
        })(),
        default => throw new InvalidArgumentException("Unknown operation \"{$job['op']}\"."),
    };

    $emit(['ok' => true, 'id' => $id]);
} catch (Throwable $exception) {
    $emit(['ok' => false, 'exception' => $exception::class, 'message' => $exception->getMessage()]);
}
