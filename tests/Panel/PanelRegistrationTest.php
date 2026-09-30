<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Panel;

use Illuminate\Support\Facades\Schema;
use PandaBear\Mlm\Commission\CommissionStatus;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\PandaMlmPlugin;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Pages\MlmOverview;
use PandaBear\Mlm\Panel\Resources\Calculations\CalculationRunResource;
use PandaBear\Mlm\Panel\Resources\Commissions\CommissionResource;
use PandaBear\Mlm\Panel\Resources\Members\MemberResource;
use PandaBear\Mlm\Panel\Resources\Payouts\PayoutBatchResource;
use PandaBear\Mlm\Panel\Resources\Payouts\PayoutRequestResource;
use PandaBear\Mlm\Panel\Resources\Periods\CommissionPeriodResource;
use PandaBear\Mlm\Panel\Resources\Plans\PlanResource;
use PandaBear\Mlm\Panel\Resources\Plans\PlanVersionResource;
use PandaBear\Mlm\Panel\Resources\Programs\ProgramResource;
use PandaBear\Mlm\Panel\Resources\Wallets\WalletResource;
use PandaBear\Mlm\Panel\Support\MlmRelationManager;
use PandaBear\Mlm\Panel\Support\WalletBalanceColumn;
use PandaBear\Mlm\Panel\Widgets\MlmOverviewStats;
use PandaPanel\Core\PanelManager;
use PandaPanel\Infolists\InfolistSchema;
use PandaPanel\Support\NavigationBuilder;
use PandaPanel\Tables\TableSchema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The plugin's surface as Panda Panel sees it (ADR-031): what it registers,
 * where it sits, what it never offers, and what the domain never imports.
 */
final class PanelRegistrationTest extends PanelTestCase
{
    private const RESOURCES = [
        ProgramResource::class,
        MemberResource::class,
        PlanResource::class,
        PlanVersionResource::class,
        CommissionPeriodResource::class,
        CalculationRunResource::class,
        CommissionResource::class,
        WalletResource::class,
        PayoutRequestResource::class,
        PayoutBatchResource::class,
    ];

    public function test_the_plugin_registers_the_phase_resources_pages_and_overview_widget(): void
    {
        $manager = $this->app->make(PanelManager::class);

        $this->assertEqualsCanonicalizing(self::RESOURCES, $manager->resources($this->panel)->all());
        $this->assertContains(MlmOverview::class, $manager->pages($this->panel)->all());
        $this->assertSame([MlmOverviewStats::class], (new MlmOverview)->widgets());
        $this->assertSame(PandaMlmPlugin::RESOURCES, self::RESOURCES);
        $this->assertNull(PlanVersionResource::navigationItem($this->panel), 'Plan versions are reached from their plan.');
        $this->assertSame('panda-mlm', PandaMlmPlugin::make()->id());
    }

    public function test_every_resource_is_prefixed_so_it_cannot_collide_with_an_applications(): void
    {
        foreach (self::RESOURCES as $resource) {
            $this->assertStringStartsWith('mlm-', $resource::slug(), $resource);
        }

        $this->assertSame('mlm-overview', MlmOverview::slug());
    }

    public function test_one_navigation_group_holds_every_entry_and_lower_records_stay_out_of_it(): void
    {
        $this->grantAll();

        $groups = $this->navigation();

        $this->assertSame([
            'MLM Overview', 'Programs', 'Members', 'Genealogy explorer', 'Plans', 'Commission periods', 'Calculation runs',
            'Commissions', 'Wallets', 'Payout requests', 'Payout batches',
        ], $groups['Network & Compensation'] ?? null);

        $this->app->setLocale('id');

        $this->assertSame([
            'Ringkasan MLM', 'Program', 'Anggota', 'Penjelajah genealogi', 'Paket', 'Periode komisi', 'Proses perhitungan',
            'Komisi', 'Dompet', 'Permintaan pencairan', 'Batch pencairan',
        ], $this->navigation()['Jaringan & Kompensasi'] ?? null);
    }

    public function test_a_user_without_capabilities_sees_no_mlm_navigation(): void
    {
        $this->grant();

        $this->assertArrayNotHasKey('Network & Compensation', $this->navigation());
    }

    public function test_no_resource_offers_create_edit_or_delete(): void
    {
        $this->grantAll();
        $program = Program::factory()->create();

        foreach (self::RESOURCES as $resource) {
            $this->assertEqualsCanonicalizing(
                array_values(array_intersect(['index', 'view'], array_keys($resource::pages()))),
                array_keys($resource::pages()),
                "{$resource} registers a write page.",
            );
            $this->assertFalse($resource::canCreate(), $resource);
            $this->assertFalse($resource::canEdit($program), $resource);
            $this->assertFalse($resource::canDelete($program), $resource);
            $this->assertFalse($resource::canDeleteAny(), $resource);
            $this->assertFalse($resource::canForceDelete($program), $resource);
            $this->assertFalse($resource::canRestore($program), $resource);

            $table = $resource::table(TableSchema::make());
            $names = [
                ...array_map(static fn ($action): string => $action->getName(), $table->getRecordActions()),
                ...array_map(static fn ($action): string => $action->getName(), $table->getBulkActions()),
                ...array_map(static fn ($action): string => $action->getName(), $table->getHeaderActions()),
                ...array_keys($resource::infolist(InfolistSchema::make())->allActions()),
            ];

            foreach ($names as $name) {
                $this->assertDoesNotMatchRegularExpression('/delete|edit|create|restore|replicate|import/', $name, "{$resource} offers [{$name}].");
            }

            $this->assertSame([], $table->getBulkActions(), "{$resource} offers bulk actions.");
            $this->assertNotNull($table->toArray(), $resource);
        }
    }

    public function test_every_relation_manager_is_read_only(): void
    {
        $this->grantAll();
        $owner = Program::factory()->create();

        foreach (self::RESOURCES as $resource) {
            foreach ($resource::relationManagers() as $manager) {
                $this->assertTrue(is_subclass_of($manager, MlmRelationManager::class), $manager);

                foreach (['canCreate', 'canAttach', 'canAssociate'] as $ability) {
                    $this->assertFalse($manager::{$ability}($owner), "{$manager}::{$ability}");
                }

                foreach (['canEdit', 'canDelete', 'canDetach', 'canDissociate', 'canForceDelete', 'canRestore'] as $ability) {
                    $this->assertFalse($manager::{$ability}($owner, $owner), "{$manager}::{$ability}");
                }
            }
        }
    }

    public function test_the_permission_catalogue_separates_viewing_from_operating_and_the_plugin_publishes_it(): void
    {
        $this->assertSame([
            MlmPermission::PROGRAMS_OPERATE, MlmPermission::MEMBERS_OPERATE, MlmPermission::NETWORK_OPERATE, MlmPermission::PLANS_OPERATE,
            MlmPermission::PERIODS_OPERATE, MlmPermission::COMMISSIONS_OPERATE, MlmPermission::PAYOUTS_OPERATE,
        ], MlmPermission::operate());
        $this->assertSame([], array_intersect(MlmPermission::view(), MlmPermission::operate()));
        $this->assertSame(MlmPermission::all(), PandaMlmPlugin::make()->permissions());
        $this->assertCount(18, array_unique(MlmPermission::all()));

        foreach (MlmPermission::all() as $permission) {
            $this->assertMatchesRegularExpression('/^mlm\.[a-z]+\.(view|operate)$/', $permission);
        }
    }

    public function test_english_and_indonesian_translations_carry_the_same_keys_and_every_key_used_exists(): void
    {
        $en = require dirname(__DIR__, 2).'/resources/lang/en/mlm.php';
        $id = require dirname(__DIR__, 2).'/resources/lang/id/mlm.php';

        $this->assertSame(array_keys($this->flatten($en)), array_keys($this->flatten($id)));

        preg_match_all("/'mlm::mlm\\.([a-z0-9_.-]+)'/", $this->panelSource(), $matches);

        // A key ending in a dot is the fixed half of one built at runtime.
        foreach (array_filter(array_unique($matches[1]), static fn (string $key): bool => ! str_ends_with($key, '.')) as $key) {
            $this->assertArrayHasKey($key, $this->flatten($en), "mlm::mlm.{$key} is not translated.");
        }

        $this->assertSame('Network & Compensation', __('mlm::mlm.navigation.group'));
        $this->app->setLocale('id');
        $this->assertSame('Jaringan & Kompensasi', __('mlm::mlm.navigation.group'));
    }

    public function test_the_domain_never_imports_panda_panel(): void
    {
        $root = dirname(__DIR__, 2).'/src';

        foreach ($this->phpFiles($root) as $path => $source) {
            if (str_starts_with($path, $root.'/Panel/') || $path === $root.'/PandaMlmPlugin.php') {
                continue;
            }

            $this->assertStringNotContainsString('PandaPanel\\', $source, "{$path} depends on Panda Panel.");
        }
    }

    public function test_the_panel_never_writes_models_directly_and_never_reaches_past_the_period_services(): void
    {
        $source = $this->panelSource();

        foreach (['->update(', '->save(', '->forceFill(', '->delete(', '::create(', '->create([', 'DB::table(', '->insert(', 'CalculationEngine', 'CommissionStatusWriter', 'SponsorEdge::query()->insert', 'PlacementEdge::query()->insert', 'PayoutBatchItem::query()->delete'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, "The panel adapter uses [{$forbidden}].");
        }

        $this->assertStringContainsString('CommissionPeriodCalculator::class)->calculate(', $source);
        $this->assertStringContainsString('CommissionPeriodFinalizer::class)->finalize(', $source);
        $this->assertStringContainsString('CommissionPeriodReleaser::class)->release(', $source);
        $this->assertStringContainsString('PayoutManager::class)->approve(', $source);
        $this->assertStringContainsString('PayoutBatchManager::class)->seal(', $source);
    }

    public function test_no_tenancy_no_paid_status_and_no_metric_shorthand(): void
    {
        $source = $this->panelSource();
        $lang = file_get_contents(dirname(__DIR__, 2).'/resources/lang/en/mlm.php').file_get_contents(dirname(__DIR__, 2).'/resources/lang/id/mlm.php');

        foreach (self::RESOURCES as $resource) {
            $this->assertNull($resource::tenantRelationship(), $resource);
        }

        $this->assertDoesNotMatchRegularExpression('/tenant/i', $source);
        $this->assertNotContains('paid', array_map(static fn (CommissionStatus $status): string => $status->value, CommissionStatus::cases()));
        $this->assertDoesNotMatchRegularExpression("/'paid'/i", $source.$lang);
        $this->assertDoesNotMatchRegularExpression('/\b(PV|BV|GV)\b/', $source.$lang);
    }

    /**
     * `panel:icons` rebuilds the application's icon registry from the
     * application and the framework only — never from a plugin — so every
     * icon here must be one the framework itself declares.
     */
    public function test_every_icon_is_one_the_framework_already_registers(): void
    {
        $framework = implode("\n", $this->phpFiles(dirname(__DIR__, 2).'/vendor/chocoalano/panel/src'));
        preg_match_all("/(?:icon\\(|Icon = |icon: |'icon' => )'([a-z0-9-]+)'/", $framework, $known);

        preg_match_all("/(?:->icon\\(|\\\$navigationIcon = )'([a-z0-9-]+)'/", $this->panelSource(), $used);
        preg_match_all("/emptyState\\([^;]*?, '([a-z0-9-]+)'\\)/", $this->panelSource(), $empty);

        $icons = array_unique([...$used[1], ...$empty[1]]);

        $this->assertNotEmpty($icons);
        $this->assertSame([], array_values(array_diff($icons, $known[1])), 'An icon the framework does not declare would not render.');
    }

    public function test_wallet_balances_are_read_from_the_ledger_never_a_stored_column(): void
    {
        $this->assertFalse(Schema::hasColumn('mlm_wallets', 'balance'));
        $this->assertInstanceOf(WalletBalanceColumn::class, WalletResource::table(TableSchema::make())->getColumn('balance'));
        $this->assertNull(WalletBalanceColumn::make('balance')->toQueryConstraint());
        $this->assertFalse(WalletBalanceColumn::make('balance')->isSortable());
        $this->assertFalse(WalletBalanceColumn::make('balance')->isSearchable());
    }

    /**
     * @return array<string, list<string>> group label => item labels
     */
    private function navigation(): array
    {
        $groups = [];

        foreach ($this->app->make(NavigationBuilder::class)->for($this->panel, 'mlm') as $group) {
            $groups[$group['label']] = array_column($group['items'], 'label');
        }

        return $groups;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<string, mixed>
     */
    private function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];

        foreach ($values as $key => $value) {
            $flat = is_array($value)
                ? [...$flat, ...$this->flatten($value, "{$prefix}{$key}.")]
                : [...$flat, "{$prefix}{$key}" => $value];
        }

        ksort($flat);

        return $flat;
    }

    private function panelSource(): string
    {
        $root = dirname(__DIR__, 2).'/src';

        return implode("\n", [...$this->phpFiles($root.'/Panel'), file_get_contents($root.'/PandaMlmPlugin.php')]);
    }

    /**
     * @return array<string, string> path => source
     */
    private function phpFiles(string $root): array
    {
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }

        return $files;
    }
}
