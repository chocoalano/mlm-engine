<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Panel;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Models\Wallet;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Period\CommissionPeriodCalculator;
use PandaBear\Mlm\Tests\Panel\Concerns\BuildsOperations;

/**
 * The operational lists cost the same number of queries whatever the page
 * holds: counts, relations and balances are read per page, never per row.
 * And the overview reports counts, never a sum across currencies.
 */
final class PanelQueryTest extends PanelTestCase
{
    use BuildsOperations;

    private const LISTS = [
        'mlm-programs', 'mlm-members', 'mlm-plans', 'mlm-commission-periods', 'mlm-calculation-runs',
        'mlm-commissions', 'mlm-wallets', 'mlm-payout-requests', 'mlm-payout-batches', 'mlm-overview', 'mlm-plan-versions',
    ];

    public function test_every_list_reads_a_fixed_number_of_queries_however_many_rows_it_shows(): void
    {
        $this->operatingProgram();
        $version = $this->activeVersion();
        $this->grantAll();

        $this->grow($version, 1, 2);
        $small = $this->queriesPerList();

        $this->grow($version, 3, 5);
        $large = $this->queriesPerList();

        $this->assertSame($small, $large);

        // And the larger lists really are larger.
        foreach (['mlm-members' => 6, 'mlm-commission-periods' => 5, 'mlm-commissions' => 5, 'mlm-wallets' => 5, 'mlm-payout-requests' => 5, 'mlm-payout-batches' => 5] as $slug => $rows) {
            $this->assertCount($rows, $this->withHeaders(['X-Inertia' => 'true'])->get("/mlm/{$slug}")->json('props.rows'), $slug);
        }
    }

    public function test_a_wallets_balance_in_the_list_is_its_exact_ledger_balance(): void
    {
        $this->operatingProgram('BOB');
        $this->grant(MlmPermission::WALLETS_VIEW);
        $this->fundedWallet($this->team['BOB'], '12.345678', 'fund:BOB');
        $this->fundedWallet($this->team['ALICE'], '9223372036854.775807', 'fund:ALICE');
        $this->settlementAccount($this->team['BOB']);
        $this->payouts()->approve($this->payoutRequest($this->team['BOB'], '0.000001', 'payout:1'), CarbonImmutable::parse('2026-03-01 11:00:00'));

        $balances = collect($this->withHeaders(['X-Inertia' => 'true'])->get('/mlm/mlm-wallets')->json('props.rows'))
            ->mapWithKeys(static fn (array $row): array => [$row['cells']['member.member_code'] => $row['cells']['balance']]);

        $this->assertSame(['ALICE' => '9223372036854.775807 IDR', 'BOB' => '12.345677 IDR'], $balances->sortKeys()->all());
    }

    public function test_the_overview_shows_four_counts_and_never_a_mixed_currency_amount(): void
    {
        $this->operatingProgram('BOB');
        $version = $this->activeVersion();
        $this->sale($this->team['BOB'], '150', '2026-01-10', 'order:1');
        $this->app->make(CommissionPeriodCalculator::class)->calculate($this->openPeriod($version));

        // A second program paying out in another currency.
        $idr = $this->team['ALICE'];
        $this->fundedWallet($idr, '100', 'fund:ALICE');
        $this->settlementAccount($idr);
        $this->payoutRequest($idr, '60', 'payout:idr');

        $other = Program::factory()->create();
        $usdMember = $this->members($other, 'ZED')['ZED'];
        $usdWallet = $this->walletAccount($usdMember, 'USD');
        $clearing = $this->systemAccounts()->openSystemAccount($other, 'USD', 'adjustment.clearing');
        $this->ledger()->post($this->postCommand($other, [[$clearing, '-50'], [$usdWallet, '50']], 'fund:ZED', 'USD'));
        $this->payouts()->request(
            member: $usdMember,
            wallet: Wallet::query()->where('member_id', $usdMember->id)->sole(),
            settlementAccount: $this->settlementAccount($usdMember, 'USD'),
            amount: '40',
            destinationType: 'bank-account',
            destinationReference: 'dest:ZED',
            requestedAt: CarbonImmutable::parse('2026-03-01 10:00:00'),
            idempotencyKey: 'payout:usd',
        );

        $this->grant(MlmPermission::DASHBOARD_VIEW);
        $stats = $this->withHeaders(['X-Inertia' => 'true'])->get('/mlm/mlm-overview')->assertOk()->json('props.widgets.0.data.stats');

        $this->assertCount(4, $stats);
        $this->assertSame([1, 1, 0, 2], array_column($stats, 'value'));
        $this->assertSame(['1', '1', '0', '2'], array_column($stats, 'display'));
        $this->assertSame('1 calculated (finalize) · 0 ready to release · 0 with input closed', $stats[1]['description']);

        foreach ($stats as $stat) {
            $this->assertIsInt($stat['value']);
            $this->assertDoesNotMatchRegularExpression('/IDR|USD|\d\.\d/', $stat['display'].' '.$stat['label']);
        }
    }

    public function test_the_overview_is_only_for_the_dashboard_capability(): void
    {
        $this->grant(...array_diff(MlmPermission::all(), [MlmPermission::DASHBOARD_VIEW]));

        $this->withHeaders(['X-Inertia' => 'true'])->get('/mlm/mlm-overview')->assertForbidden();
    }

    /**
     * Members sponsored by ALICE, each with a sale in its own month, that
     * month's period calculated, a funded wallet, a payout request and a
     * payout batch — and a program with nothing in it.
     */
    private function grow(PlanVersion $version, int $from, int $to): void
    {
        foreach (range($from, $to) as $i) {
            $code = "M{$i}";
            $member = $this->members($this->program, $code)[$code];
            $this->sponsorAt($member, $this->team['ALICE'], '2026-01-01 00:00:00');

            $month = CarbonImmutable::parse("2026-{$i}-01");
            $this->sale($member, '150', $month->addDays(9)->format('Y-m-d'), "order:{$i}");
            $period = $this->openPeriod($version, $month->format('Y-m-d'), $month->addMonth()->format('Y-m-d'), $month->addMonth()->addDays(14)->format('Y-m-d'));
            $this->app->make(CommissionPeriodCalculator::class)->calculate($period);

            $this->fundedWallet($member, '100', "fund:{$code}");
            $this->settlementAccount($member);
            $this->payoutRequest($member, '10', "payout:{$code}");
            $this->payoutBatches()->create($this->program, 'IDR', "batch:{$i}");

            Program::factory()->create();
        }
    }

    /**
     * @return array<string, int>
     */
    private function queriesPerList(): array
    {
        $counts = [];

        foreach (self::LISTS as $slug) {
            $connection = DB::connection();
            $connection->flushQueryLog();
            $connection->enableQueryLog();

            $this->withHeaders(['X-Inertia' => 'true'])->get("/mlm/{$slug}")->assertOk();

            $counts[$slug] = count($connection->getQueryLog());
            $connection->disableQueryLog();
        }

        return $counts;
    }
}
