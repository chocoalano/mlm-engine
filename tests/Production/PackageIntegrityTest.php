<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Production;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\PackageManifest;
use PandaBear\Mlm\Binary\BinaryGenealogy;
use PandaBear\Mlm\Binary\BinaryPlacementManager;
use PandaBear\Mlm\Calculation\CalculationEngine;
use PandaBear\Mlm\Commission\CommissionAdjustmentEngine;
use PandaBear\Mlm\Commission\CommissionLifecycle;
use PandaBear\Mlm\Commission\CommissionPoster;
use PandaBear\Mlm\Commission\CommissionStrategyRegistry;
use PandaBear\Mlm\Commission\HybridCalculationEngine;
use PandaBear\Mlm\Finance\LedgerAccountManager;
use PandaBear\Mlm\Finance\LedgerBalanceReader;
use PandaBear\Mlm\Finance\LedgerRecorder;
use PandaBear\Mlm\Finance\WalletManager;
use PandaBear\Mlm\Genealogy\PlacementGenealogy;
use PandaBear\Mlm\Genealogy\SponsorGenealogy;
use PandaBear\Mlm\Matrix\MatrixGenealogy;
use PandaBear\Mlm\Matrix\MatrixNetworkManager;
use PandaBear\Mlm\Matrix\MatrixPlacementManager;
use PandaBear\Mlm\Metrics\MetricEngine;
use PandaBear\Mlm\Metrics\MetricRegistry;
use PandaBear\Mlm\PandaMlmPlugin;
use PandaBear\Mlm\PandaMlmServiceProvider;
use PandaBear\Mlm\Payout\PayoutBatchManager;
use PandaBear\Mlm\Payout\PayoutManager;
use PandaBear\Mlm\Period\CommissionPeriodCalculator;
use PandaBear\Mlm\Period\CommissionPeriodFinalizer;
use PandaBear\Mlm\Period\CommissionPeriodManager;
use PandaBear\Mlm\Period\CommissionPeriodReleaser;
use PandaBear\Mlm\Planning\PlanComponentDriverRegistry;
use PandaBear\Mlm\Planning\PlanDefinitionCloner;
use PandaBear\Mlm\Planning\PlanDefinitionEditor;
use PandaBear\Mlm\Planning\PlanDefinitionValidator;
use PandaBear\Mlm\Planning\PlanVersionLifecycle;
use PandaBear\Mlm\Program\ProgramManager;
use PandaBear\Mlm\Qualification\QualificationEngine;
use PandaBear\Mlm\Rank\RankEngine;
use PandaBear\Mlm\Support\PandaMlmConfig;
use PandaBear\Mlm\Tests\TestCase;
use PandaBear\Mlm\Volume\VolumeRecorder;
use PandaBear\Mlm\Volume\VolumeTotals;
use Symfony\Component\Process\Process;

/**
 * What a consumer installs (ADR-031, docs/production-readiness.md): the
 * archive Composer builds holds every runtime file and none of the
 * development-only ones, the package metadata says what is true, Laravel
 * discovers the provider, and every public service resolves.
 */
final class PackageIntegrityTest extends TestCase
{
    /**
     * Files and directories a consumer's application needs at runtime.
     */
    private const RUNTIME = [
        'composer.json',
        'README.md',
        'LICENSE',
        'CHANGELOG.md',
        'config/mlm.php',
        'src/PandaMlmServiceProvider.php',
        'src/PandaMlmPlugin.php',
        'src/Panel/MlmPermission.php',
        'src/Panel/Pages/GenealogyExplorer.php',
        'src/Program/ProgramManager.php',
        'resources/lang/en/mlm.php',
        'resources/lang/id/mlm.php',
        'database/migrations/2026_09_28_000001_create_mlm_programs_table.php',
        'database/migrations/2026_09_28_000040_create_mlm_payout_batch_items_table.php',
        'database/factories/ProgramFactory.php',
        'docs/public-api.md',
        'docs/production-readiness.md',
    ];

    /**
     * Development-only paths the archive leaves out.
     */
    private const DEVELOPMENT = ['tests', 'phpunit.xml', 'pint.json', 'composer.lock', '.gitattributes', '.gitignore', 'scripts'];

    public function test_the_archive_keeps_every_runtime_file_and_drops_development_material(): void
    {
        $exported = $this->exportedFiles();

        foreach (self::RUNTIME as $path) {
            $this->assertContains($path, $exported, "The archive would lose [{$path}].");
        }

        foreach ((array) glob($this->root().'/database/migrations/*.php') as $migration) {
            $this->assertContains('database/migrations/'.basename((string) $migration), $exported, 'A migration would be missing from the archive.');
        }

        foreach ($exported as $path) {
            foreach (self::DEVELOPMENT as $development) {
                $this->assertFalse($path === $development || str_starts_with($path, $development.'/'), "The archive would ship [{$path}].");
            }
        }

        // Every autoloaded directory survives the archive.
        foreach ($this->composerJson()['autoload']['psr-4'] as $directory) {
            $this->assertNotEmpty(array_filter($exported, static fn (string $path): bool => str_starts_with($path, rtrim($directory, '/').'/')), "Autoload path [{$directory}] would be empty.");
        }
    }

    public function test_the_package_metadata_says_what_is_true(): void
    {
        $composer = $this->composerJson();

        $this->assertSame(['pandabear/mlm', 'library', 'MIT', '^8.2'], [$composer['name'], $composer['type'], $composer['license'], $composer['require']['php']]);
        $this->assertSame('^0.5.7', $composer['require']['chocoalano/panel']);
        $this->assertSame([PandaMlmServiceProvider::class], $composer['extra']['laravel']['providers']);
        $this->assertSame(['stable', true], [$composer['minimum-stability'], $composer['prefer-stable']]);
        $this->assertSame($composer['require']['chocoalano/panel'], PandaMlmPlugin::make()->metadata()->requiresPanel);
        $this->assertFileExists($this->root().'/LICENSE');
        $this->assertStringContainsString('MIT License', (string) file_get_contents($this->root().'/LICENSE'));

        foreach (['illuminate/database', 'illuminate/support'] as $framework) {
            $this->assertSame('^12.0|^13.0', $composer['require'][$framework]);
        }
    }

    /**
     * Laravel's own package discovery, fed this package's composer.json the
     * way an application's vendor/composer/installed.json carries it: the
     * provider is found with nothing registered by hand.
     */
    public function test_laravel_discovers_the_service_provider(): void
    {
        $base = sys_get_temp_dir().'/mlm-discovery-'.bin2hex(random_bytes(4));
        $files = new Filesystem;
        $files->ensureDirectoryExists($base.'/vendor/composer');
        $files->ensureDirectoryExists($base.'/bootstrap/cache');
        $files->put($base.'/vendor/composer/installed.json', (string) json_encode(['packages' => [
            ['name' => 'pandabear/mlm', 'extra' => $this->composerJson()['extra']],
        ]]));

        try {
            $manifest = new PackageManifest($files, $base, $base.'/bootstrap/cache/packages.php');

            $this->assertContains(PandaMlmServiceProvider::class, $manifest->providers());
        } finally {
            $files->deleteDirectory($base);
        }
    }

    /**
     * Every public service, built by the container from a clean application
     * — a wiring regression or a circular dependency fails here.
     */
    public function test_every_public_service_resolves_from_the_container(): void
    {
        foreach ([
            PandaMlmConfig::class, MetricRegistry::class, MetricEngine::class, PlanComponentDriverRegistry::class, CommissionStrategyRegistry::class,
            ProgramManager::class, SponsorGenealogy::class, PlacementGenealogy::class, BinaryPlacementManager::class, BinaryGenealogy::class,
            MatrixNetworkManager::class, MatrixPlacementManager::class, MatrixGenealogy::class,
            PlanVersionLifecycle::class, PlanDefinitionEditor::class, PlanDefinitionValidator::class, PlanDefinitionCloner::class,
            QualificationEngine::class, RankEngine::class, VolumeRecorder::class, VolumeTotals::class,
            CalculationEngine::class, HybridCalculationEngine::class,
            CommissionPeriodManager::class, CommissionPeriodCalculator::class, CommissionPeriodFinalizer::class, CommissionPeriodReleaser::class,
            CommissionLifecycle::class, CommissionPoster::class, CommissionAdjustmentEngine::class,
            LedgerRecorder::class, LedgerAccountManager::class, LedgerBalanceReader::class, WalletManager::class,
            PayoutManager::class, PayoutBatchManager::class,
        ] as $service) {
            $this->assertInstanceOf($service, $this->app->make($service), $service);
        }

        // Shared registries stay shared: registrations are configuration.
        $this->assertSame($this->app->make(MetricRegistry::class), $this->app->make(MetricRegistry::class));
        $this->assertSame($this->app->make(CommissionStrategyRegistry::class), $this->app->make(CommissionStrategyRegistry::class));
    }

    /**
     * What `git archive` — and Composer's export filter — leaves in: every
     * candidate file of the working tree whose path, and every directory
     * above it, carries no `export-ignore`.
     *
     * @return list<string>
     */
    private function exportedFiles(): array
    {
        if (! is_dir($this->root().'/.git')) {
            $this->markTestSkipped('Not a git checkout: the archive rules are read from .gitattributes by git.');
        }

        $list = new Process(['git', 'ls-files', '--cached', '--others', '--exclude-standard'], $this->root());
        $list->mustRun();
        $files = array_values(array_filter(explode("\n", $list->getOutput()), fn (string $file): bool => $file !== '' && file_exists($this->root().'/'.$file)));

        $paths = [];

        foreach ($files as $file) {
            $segments = explode('/', $file);

            for ($i = 1; $i <= count($segments); $i++) {
                $paths[implode('/', array_slice($segments, 0, $i))] = true;
            }
        }

        $check = new Process(['git', 'check-attr', '--stdin', 'export-ignore'], $this->root());
        $check->setInput(implode("\n", array_keys($paths))."\n");
        $check->mustRun();

        $ignored = [];

        foreach (explode("\n", trim($check->getOutput())) as $line) {
            if (str_ends_with($line, ': export-ignore: set')) {
                $ignored[substr($line, 0, -strlen(': export-ignore: set'))] = true;
            }
        }

        return array_values(array_filter($files, static function (string $file) use ($ignored): bool {
            $segments = explode('/', $file);

            for ($i = 1; $i <= count($segments); $i++) {
                if (isset($ignored[implode('/', array_slice($segments, 0, $i))])) {
                    return false;
                }
            }

            return true;
        }));
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
