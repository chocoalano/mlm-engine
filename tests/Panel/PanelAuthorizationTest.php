<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Panel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Testing\TestResponse;
use PandaBear\Mlm\Models\CalculationRun;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\PayoutBatch;
use PandaBear\Mlm\Models\PayoutRequest;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Models\Wallet;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Period\CommissionPeriodCalculator;
use PandaBear\Mlm\Tests\Panel\Concerns\BuildsOperations;

/**
 * Every page answers to its capability and to nothing else — no role name
 * is ever asked (ADR-031).
 */
final class PanelAuthorizationTest extends PanelTestCase
{
    use BuildsOperations;

    /** @var array<string, array{string, Model}> slug => capability, a record */
    private array $pages;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operatingProgram('BOB');
        $version = $this->activeVersion();
        $this->sale($this->team['BOB'], '150', '2026-01-10', 'order:1');
        $period = $this->openPeriod($version);
        $this->app->make(CommissionPeriodCalculator::class)->calculate($period);
        $this->fundedWallet($this->team['ALICE'], '100', 'fund:ALICE');
        $this->settlementAccount($this->team['ALICE']);
        $request = $this->payoutRequest($this->team['ALICE'], '10', 'payout:1');
        $batch = $this->payoutBatches()->create($this->program, 'IDR', 'batch:1');

        $this->pages = [
            'mlm-programs' => [MlmPermission::PROGRAMS_VIEW, $this->program],
            'mlm-members' => [MlmPermission::MEMBERS_VIEW, $this->team['BOB']],
            'mlm-plans' => [MlmPermission::PLANS_VIEW, $version->plan],
            'mlm-commission-periods' => [MlmPermission::PERIODS_VIEW, $period],
            'mlm-calculation-runs' => [MlmPermission::CALCULATIONS_VIEW, CalculationRun::query()->sole()],
            'mlm-commissions' => [MlmPermission::COMMISSIONS_VIEW, Commission::query()->sole()],
            'mlm-wallets' => [MlmPermission::WALLETS_VIEW, Wallet::query()->sole()],
            'mlm-payout-requests' => [MlmPermission::PAYOUTS_VIEW, $request],
            'mlm-payout-batches' => [MlmPermission::PAYOUTS_VIEW, $batch],
        ];
    }

    public function test_a_guest_and_a_user_without_capabilities_reach_nothing(): void
    {
        foreach ([false, true] as $signedIn) {
            if ($signedIn) {
                $this->grant();
            }

            // A guest is unauthenticated (401), a user without the
            // capability unauthorized (403): refused either way.
            $denied = $signedIn ? 403 : 401;

            foreach ($this->pages as $slug => [, $record]) {
                $this->inertia("/mlm/{$slug}")->assertStatus($denied);
                $this->inertia("/mlm/{$slug}/{$record->getKey()}")->assertStatus($denied);
            }

            $this->inertia('/mlm/mlm-overview')->assertStatus($denied);
            $this->inertia('/mlm/mlm-plan-versions/'.PlanVersion::query()->value('id'))->assertStatus($denied);
        }
    }

    public function test_each_view_capability_opens_its_own_list_and_detail_and_no_other(): void
    {
        foreach ($this->pages as $slug => [$permission, $record]) {
            $this->grant($permission);

            $this->inertia("/mlm/{$slug}")->assertOk()->assertJsonPath('component', 'panel/resources/Index');
            $this->inertia("/mlm/{$slug}/{$record->getKey()}")->assertOk()->assertJsonPath('component', 'panel/resources/View');

            foreach ($this->pages as $other => [$otherPermission]) {
                if ($otherPermission !== $permission) {
                    $this->inertia("/mlm/{$other}")->assertForbidden();
                }
            }
        }
    }

    public function test_the_overview_and_plan_version_detail_need_their_capabilities(): void
    {
        $this->grant(MlmPermission::DASHBOARD_VIEW);
        $this->inertia('/mlm/mlm-overview')->assertOk()->assertJsonPath('component', 'panel/Page');

        $version = PlanVersion::query()->sole();
        $this->inertia("/mlm/mlm-plan-versions/{$version->id}")->assertForbidden();

        $this->grant(MlmPermission::PLANS_VIEW);
        $this->inertia("/mlm/mlm-plan-versions/{$version->id}")->assertOk();
    }

    public function test_the_ledger_is_reachable_only_with_the_ledger_capability(): void
    {
        $wallet = Wallet::query()->sole();

        $this->grant(MlmPermission::WALLETS_VIEW);
        $relations = $this->inertia("/mlm/mlm-wallets/{$wallet->id}")->assertOk()->json('props.relations');
        $this->assertSame([], $relations);

        $this->grant(MlmPermission::WALLETS_VIEW, MlmPermission::LEDGER_VIEW);
        $postings = collect($this->inertia("/mlm/mlm-wallets/{$wallet->id}")->json('props.relations'))->firstWhere('key', 'ledger-postings');

        $this->assertSame(['100 IDR', 'adjustment'], [$postings['rows'][0]['cells']['amount_millionths'], $postings['rows'][0]['cells']['transaction.type']]);
        $this->assertSame([], $postings['headerActions']);
    }

    public function test_every_detail_page_renders_its_relations_read_only(): void
    {
        $this->grantAll();

        foreach ($this->pages as $slug => [, $record]) {
            $page = $this->inertia("/mlm/{$slug}/{$record->getKey()}")->assertOk();

            $this->assertSame([], $page->json('props.page.headerActions'), "{$slug} offers a header action.");

            foreach ($page->json('props.relations') as $relation) {
                $this->assertSame([], $relation['headerActions'], "{$slug} / {$relation['key']} offers a header action.");
            }
        }

        $version = PlanVersion::query()->sole();
        $components = collect($this->inertia("/mlm/mlm-plan-versions/{$version->id}")->assertOk()->json('props.relations'))->firstWhere('key', 'components');

        $this->assertSame('direct-sponsor.fixed', $components['rows'][0]['cells']['strategy']);
        $this->assertStringContainsString('"amount": "10"', $components['rows'][0]['cells']['parameters']);
    }

    public function test_the_payout_destination_is_masked_in_the_list_and_whole_on_the_detail(): void
    {
        $this->grant(MlmPermission::PAYOUTS_VIEW);
        $request = PayoutRequest::query()->sole();

        $row = $this->inertia('/mlm/mlm-payout-requests')->json('props.rows.0.cells');
        $this->assertSame('••••••LICE', $row['destination_reference']);

        $detail = collect($this->inertia("/mlm/mlm-payout-requests/{$request->id}")->json('props.infolist.schema'))
            ->flatMap(static fn (array $section): array => $section['schema'])
            ->pluck('value', 'name');
        $this->assertSame('dest:ALICE', $detail['destination_reference']);
        $this->assertArrayNotHasKey('reservation_ledger_transaction_id', $detail->all());
    }

    public function test_an_unknown_batch_or_request_is_not_found_rather_than_shown(): void
    {
        $this->grantAll();

        $this->inertia('/mlm/mlm-payout-batches/'.str_repeat('0', 26))->assertNotFound();
        $this->assertSame(1, PayoutBatch::query()->count());
    }

    private function inertia(string $uri): TestResponse
    {
        return $this->withHeaders(['X-Inertia' => 'true'])->get($uri);
    }
}
