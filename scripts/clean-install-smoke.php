<?php

/*
 * Run inside a consumer application by scripts/clean-install-smoke.sh — what
 * the application sees of the installed package. Development only.
 *
 *   php mlm-smoke.php checks [connection]   discovery, config, migrations, panel, services
 *   php mlm-smoke.php backend [connection]  a payout cycle, no panel and nobody signed in
 *
 * [connection] is the `mlm.database.connection` the application configured;
 * when given, nothing of the package may reach the default connection.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PandaBear\Mlm\Commission\CommissionLifecycle;
use PandaBear\Mlm\Commission\CommissionPoster;
use PandaBear\Mlm\Commission\CommissionStatus;
use PandaBear\Mlm\Finance\LedgerAccountManager;
use PandaBear\Mlm\Finance\LedgerBalanceReader;
use PandaBear\Mlm\Genealogy\PlacementGenealogy;
use PandaBear\Mlm\Genealogy\SponsorGenealogy;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\LedgerTransaction;
use PandaBear\Mlm\Models\Wallet;
use PandaBear\Mlm\PandaMlmServiceProvider;
use PandaBear\Mlm\Payout\PayoutManager;
use PandaBear\Mlm\Payout\PayoutRequestStatus;
use PandaBear\Mlm\Period\CommissionPeriodCalculator;
use PandaBear\Mlm\Period\CommissionPeriodFinalizer;
use PandaBear\Mlm\Period\CommissionPeriodManager;
use PandaBear\Mlm\Period\CommissionPeriodReleaser;
use PandaBear\Mlm\Planning\PlanDefinitionEditor;
use PandaBear\Mlm\Planning\PlanVersionLifecycle;
use PandaBear\Mlm\Program\ProgramManager;
use PandaBear\Mlm\Support\PandaMlmConfig;
use PandaBear\Mlm\Volume\Quantity;
use PandaBear\Mlm\Volume\RecordVolume;
use PandaBear\Mlm\Volume\VolumeRecorder;
use PandaPanel\Core\PanelManager;

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? 'checks';
$connection = ($argv[2] ?? '') === '' ? null : $argv[2];
$mlm = $connection ?? config('database.default');
$failed = false;

$check = static function (string $name, callable $test) use (&$failed): void {
    try {
        $outcome = $test() === true ? 'ok' : 'FAILED';
    } catch (Throwable $e) {
        $outcome = 'FAILED '.$e::class.': '.$e->getMessage();
    }

    $failed = $failed || $outcome !== 'ok';
    echo str_pad($name, 22), $outcome, PHP_EOL;
};

$package = base_path('vendor/pandabear/mlm');
$migrations = array_map(static fn (string $file): string => basename($file, '.php'), glob($package.'/database/migrations/*.php'));
$mlmTablesOn = static fn (string $name): array => array_values(array_filter(
    array_column(Schema::connection($name)->getTables(), 'name'),
    static fn (string $table): bool => str_starts_with($table, 'mlm_'),
));

if ($mode === 'checks') {
    $check('provider', static fn (): bool => $app->getProvider(PandaMlmServiceProvider::class) !== null
        && in_array(PandaMlmServiceProvider::class, (require $app->getCachedPackagesPath())['pandabear/mlm']['providers'] ?? [], true));
    $check('config', static fn (): bool => config('mlm.queue.name') === 'mlm'
        && config('mlm.cache.prefix') === 'mlm'
        && app(PandaMlmConfig::class)->databaseConnection() === $connection);
    echo '  (configuration ', $app->configurationIsCached() ? 'cached' : 'not cached', ', routes ', $app->routesAreCached() ? 'cached' : 'not cached', ')', PHP_EOL;
    $check('migrations shipped', static fn (): bool => count($migrations) === 40 && end($migrations) === '2026_09_28_000040_create_mlm_payout_batch_items_table');
    $check('migrations found', static fn (): bool => in_array(realpath($package.'/database/migrations'), array_map('realpath', app('migrator')->paths()), true));
    $check('migrations ran', static fn (): bool => array_diff($migrations, DB::table('migrations')->pluck('migration')->all()) === []);
    $check('tables', static fn (): bool => count($mlmTablesOn($mlm)) > 30 && Schema::connection($mlm)->hasTable('mlm_payout_batch_items'));
    $check('no leak to default', static fn (): bool => $connection === null || $mlmTablesOn(config('database.default')) === []);
    $check('translations', static fn (): bool => __('mlm::mlm.navigation.group', [], 'en') === 'Network & Compensation'
        && __('mlm::mlm.navigation.group', [], 'id') === 'Jaringan & Kompensasi');
    $check('panel plugin', static fn (): bool => app(PanelManager::class)->has('mlm') && app(PanelManager::class)->get('mlm')->hasPlugin('panda-mlm'));
    $check('panel routes', static function (): bool {
        $uris = array_map(static fn ($route): string => $route->uri(), app('router')->getRoutes()->getRoutes());

        foreach (['mlm-overview', 'mlm-programs', 'mlm-members', 'mlm-genealogy', 'mlm-plans', 'mlm-plan-versions', 'mlm-commission-periods', 'mlm-calculation-runs', 'mlm-commissions', 'mlm-wallets', 'mlm-payout-requests', 'mlm-payout-batches'] as $slug) {
            if (! in_array("mlm/{$slug}", $uris, true)) {
                return false;
            }
        }

        return true;
    });
    $check('services', static function (): bool {
        foreach ([
            'Support\PandaMlmConfig', 'Metrics\MetricRegistry', 'Metrics\MetricEngine', 'Planning\PlanComponentDriverRegistry', 'Commission\CommissionStrategyRegistry',
            'Program\ProgramManager', 'Genealogy\SponsorGenealogy', 'Genealogy\PlacementGenealogy', 'Binary\BinaryPlacementManager', 'Binary\BinaryGenealogy',
            'Matrix\MatrixNetworkManager', 'Matrix\MatrixPlacementManager', 'Matrix\MatrixGenealogy',
            'Planning\PlanVersionLifecycle', 'Planning\PlanDefinitionEditor', 'Planning\PlanDefinitionValidator', 'Planning\PlanDefinitionCloner',
            'Qualification\QualificationEngine', 'Rank\RankEngine', 'Volume\VolumeRecorder', 'Volume\VolumeTotals',
            'Calculation\CalculationEngine', 'Commission\HybridCalculationEngine',
            'Period\CommissionPeriodManager', 'Period\CommissionPeriodCalculator', 'Period\CommissionPeriodFinalizer', 'Period\CommissionPeriodReleaser',
            'Commission\CommissionLifecycle', 'Commission\CommissionPoster', 'Commission\CommissionAdjustmentEngine',
            'Finance\LedgerRecorder', 'Finance\LedgerAccountManager', 'Finance\LedgerBalanceReader', 'Finance\WalletManager',
            'Payout\PayoutManager', 'Payout\PayoutBatchManager',
        ] as $service) {
            $class = 'PandaBear\\Mlm\\'.$service;

            if (! app($class) instanceof $class) {
                return false;
            }
        }

        return true;
    });
    $check('no development files', static fn (): bool => ! file_exists($package.'/tests') && ! file_exists($package.'/scripts')
        && ! file_exists($package.'/composer.lock') && ! file_exists($package.'/phpunit.xml') && is_file($package.'/LICENSE'));
}

if ($mode === 'backend') {
    $check('no panel, no user', static fn (): bool => app(PanelManager::class)->currentPanel() === null && auth()->guest());

    $check('payout cycle', static function () use ($mlm): bool {
        $programs = app(ProgramManager::class);
        $program = $programs->create('SMOKE', 'Smoke Program');
        $alice = $programs->join($program, 'ALICE', CarbonImmutable::parse('2025-12-01'), 'user', '1');
        $bob = $programs->join($program, 'BOB', CarbonImmutable::parse('2025-12-01'), 'user', '2');

        // The network as it stood on 1 January, before the sale.
        Carbon::setTestNow('2026-01-01 00:00:00');
        app(SponsorGenealogy::class)->assignSponsor($bob, $alice);
        app(PlacementGenealogy::class)->place($bob, $alice);
        Carbon::setTestNow();

        $accounts = app(LedgerAccountManager::class);
        $source = $accounts->openSystemAccount($program, 'IDR', 'commission.payable');
        $settlement = $accounts->openSystemAccount($program, 'IDR', 'payout.settlement');

        $lifecycle = app(PlanVersionLifecycle::class);
        $draft = $lifecycle->draft($programs->addPlan($program, 'COMP', 'Compensation'));
        app(PlanDefinitionEditor::class)->addComponent($draft, 'direct', 'commission.strategy', 'Direct', [
            'strategy' => 'direct-sponsor.fixed',
            'currency' => 'IDR',
            'source_account' => 'commission.payable',
            'parameters' => ['volume_type' => 'sales', 'source_type' => 'order', 'minimum_quantity' => '1', 'amount' => '10'],
        ]);
        $lifecycle->markValidated($draft);
        $lifecycle->publish($draft->refresh());
        $version = $lifecycle->activate($draft->refresh());

        app(VolumeRecorder::class)->record(new RecordVolume($bob, 'sales', Quantity::of('150'), 'order', 'ORDER-1', 'order:1', CarbonImmutable::parse('2026-01-10')));

        $period = app(CommissionPeriodManager::class)->create($program, $version, $source, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-02-01'), CarbonImmutable::parse('2026-02-15'), 'period:2026-01');
        app(CommissionPeriodCalculator::class)->calculate($period);
        $commissions = app(CommissionLifecycle::class);
        $commission = $commissions->approve($commissions->markPending(Commission::query()->sole()));
        app(CommissionPeriodFinalizer::class)->finalize($period);
        app(CommissionPeriodReleaser::class)->release($period, CarbonImmutable::parse('2026-02-15'));
        $posted = app(CommissionPoster::class)->post($commission->refresh());

        $wallet = Wallet::query()->where('member_id', $alice->id)->sole();
        $balance = static fn (): string => app(LedgerBalanceReader::class)->forWallet($wallet)->value();

        if ($posted->status !== CommissionStatus::Posted || $balance() !== '10') {
            return false;
        }

        $payouts = app(PayoutManager::class);
        $request = $payouts->request($alice, $wallet, $settlement, '10', 'bank-account', 'ref-alice', CarbonImmutable::parse('2026-03-01 10:00:00'), 'payout:1');
        $payouts->approve($request, CarbonImmutable::parse('2026-03-01 11:00:00'));
        $payouts->startProcessing($request, CarbonImmutable::parse('2026-03-01 12:00:00'));
        $settled = $payouts->settle($request, 'BANK-1', CarbonImmutable::parse('2026-03-02 09:00:00'));

        return $settled->status === PayoutRequestStatus::Settled
            && $balance() === '0'
            && LedgerTransaction::query()->count() === 2
            && $posted->getConnection()->getName() === $mlm
            && DB::connection($mlm)->table('mlm_ledger_transactions')->count() === 2;
    });

    $check('no leak to default', static fn (): bool => $connection === null || $mlmTablesOn(config('database.default')) === []);
}

exit($failed ? 1 : 0);
