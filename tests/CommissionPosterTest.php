<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Commission\CommissionCandidate;
use PandaBear\Mlm\Commission\CommissionStatus;
use PandaBear\Mlm\Exceptions\InvalidCommissionPosting;
use PandaBear\Mlm\Models\CalculationRun;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\LedgerTransaction;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\Wallet;
use PandaBear\Mlm\Tests\Concerns\BuildsCommissions;
use PandaBear\Mlm\Tests\Concerns\BuildsLedgers;
use PandaBear\Mlm\Tests\Concerns\BuildsPlanDefinitions;
use RuntimeException;

/**
 * An approved commission moves money once, through the ledger: the run's
 * source account to the member's wallet, when it was earned — and a
 * reversal moves it back, at a moment the caller gives.
 */
final class CommissionPosterTest extends DatabaseTestCase
{
    use BuildsCommissions;
    use BuildsLedgers;
    use BuildsPlanDefinitions;

    private Plan $plan;

    private Member $alice;

    private Member $bob;

    private CalculationRun $run;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = Plan::factory()->create();
        $this->alice = Member::factory()->for($this->plan->program)->create(['member_code' => 'ALICE']);
        $this->bob = Member::factory()->for($this->plan->program)->create(['member_code' => 'BOB']);
        $this->run = $this->calculate($this->commissionComponent(['parameters' => ['amount' => '125.000001']], $this->plan));
    }

    public function test_an_approved_commission_is_posted_from_the_source_account_to_the_members_wallet(): void
    {
        $commission = $this->approved($this->commissionOf($this->alice));
        $this->travelTo(CarbonImmutable::parse('2026-07-05 08:00:00'));

        $posted = $this->poster()->post($commission);

        $wallet = Wallet::query()->where('member_id', $this->alice->id)->sole();
        $source = $this->run->sourceAccount;
        $transaction = $posted->ledgerTransaction;
        $this->assertNotNull($transaction);

        $this->assertSame([CommissionStatus::Posted, '2026-07-05 08:00:00'], [$posted->status, $posted->posted_at?->format('Y-m-d H:i:s')]);
        $this->assertSame(
            [$this->plan->program_id, 'IDR', 'commission', 'commission', $commission->id, "commission.post.{$commission->id}", '2026-06-30 23:59:59', null],
            [$transaction->program_id, $transaction->currency, $transaction->type, $transaction->source_type, $transaction->source_id, $transaction->idempotency_key, $transaction->occurred_at->format('Y-m-d H:i:s'), $transaction->reversal_of_id],
        );
        $this->assertEqualsCanonicalizing([$source->id => '-125.000001', $wallet->account?->id => '125.000001'], $this->postingsOf($transaction));
        $this->assertSame('125.000001', $this->balances()->forWallet($wallet)->value());
        $this->assertSame('-125.000001', $this->balances()->forAccount($source)->value());
        $this->assertSame($this->run->source_ledger_account_id, $source->id);
    }

    public function test_posting_one_commission_posts_no_other(): void
    {
        $this->poster()->post($this->approved($this->commissionOf($this->alice)));

        $this->assertSame(CommissionStatus::Calculated, $this->commissionOf($this->bob)->status);
        $this->assertSame(1, LedgerTransaction::query()->count());
        $this->assertSame(0, Wallet::query()->where('member_id', $this->bob->id)->count());
    }

    public function test_one_member_can_earn_several_commissions_in_a_run(): void
    {
        $strategy = $this->scriptedStrategy();
        $component = $this->commissionComponent(['strategy' => 'test.scripted', 'parameters' => []], $this->plan);
        $strategy->script = fn (): iterable => [
            new CommissionCandidate('order:1', $this->alice, '10', CarbonImmutable::parse('2026-06-10')),
            new CommissionCandidate('order:2', $this->alice, '20', CarbonImmutable::parse('2026-06-20')),
            new CommissionCandidate('order:3', $this->bob, '30', CarbonImmutable::parse('2026-06-25')),
        ];

        $commissions = $this->calculate($component, key: 'orders:2026-06')->commissions()->get();

        foreach ($commissions as $commission) {
            $this->poster()->post($this->approved($commission));
        }

        $this->assertSame('30', $this->balances()->forWallet(Wallet::query()->where('member_id', $this->alice->id)->sole())->value());
        $this->assertSame('30', $this->balances()->forWallet(Wallet::query()->where('member_id', $this->bob->id)->sole())->value());
        $this->assertSame(['2026-06-10 00:00:00', '2026-06-20 00:00:00', '2026-06-25 00:00:00'], $commissions->map(fn (Commission $commission): string => $commission->refresh()->ledgerTransaction?->occurred_at->format('Y-m-d H:i:s') ?? '')->all());
    }

    public function test_posting_again_returns_the_posted_commission_and_moves_no_more_money(): void
    {
        $commission = $this->approved($this->commissionOf($this->alice));
        $posted = $this->poster()->post($commission);
        $before = $this->ledgerRows();

        $again = $this->poster()->post($commission);

        $this->assertSame([$posted->id, $posted->ledger_transaction_id], [$again->id, $again->ledger_transaction_id]);
        $this->assertSame($before, $this->ledgerRows());
        $this->assertSame('125.000001', $this->balances()->forWallet(Wallet::query()->sole())->value());
    }

    public function test_a_posted_commission_whose_ledger_transaction_does_not_post_it_is_refused(): void
    {
        $posted = $this->poster()->post($this->approved($this->commissionOf($this->alice)));
        $other = $this->postLedger($this->plan->program, [[$this->run->sourceAccount, '-1'], [$this->walletAccount($this->alice), '1']], 'adjustment:ADJ-9', 'ADJ-9');

        // Only raw writes point a commission at another transaction, or at none.
        DB::table('mlm_commissions')->where('id', $posted->id)->update(['ledger_transaction_id' => $other->id]);

        try {
            $this->poster()->post($posted);
            $this->fail('A commission posted by another transaction was accepted.');
        } catch (InvalidCommissionPosting $exception) {
            $this->assertStringContainsString("its ledger transaction [{$other->id}] does not post it", $exception->getMessage());
        }

        DB::table('mlm_commissions')->where('id', $posted->id)->update(['ledger_transaction_id' => null]);

        $this->expectException(InvalidCommissionPosting::class);
        $this->expectExceptionMessage('its ledger transaction is missing');

        $this->poster()->post($posted);
    }

    public function test_a_ledger_failure_leaves_the_commission_approved_and_no_money_moved(): void
    {
        $commission = $this->approved($this->commissionOf($this->alice));
        $before = $this->ledgerRows();
        $this->failInsertsInto('mlm_ledger_postings');

        try {
            $this->poster()->post($commission);
            $this->fail('The commission was posted although the ledger failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The disk is full.', $exception->getMessage());
        }

        $this->assertSame(CommissionStatus::Approved, $commission->refresh()->status);
        $this->assertNull($commission->ledger_transaction_id);
        $this->assertSame($before, $this->ledgerRows());
    }

    public function test_a_posted_commission_is_reversed_through_the_ledger_at_the_moment_given(): void
    {
        $posted = $this->poster()->post($this->approved($this->commissionOf($this->alice)));
        $wallet = Wallet::query()->sole();
        $original = LedgerTransaction::query()->findOrFail($posted->ledger_transaction_id);
        $originalRows = [(array) DB::table('mlm_ledger_transactions')->where('id', $original->id)->first(), DB::table('mlm_ledger_postings')->where('ledger_transaction_id', $original->id)->orderBy('id')->get()->all()];
        $this->travelTo(CarbonImmutable::parse('2026-08-02 10:00:00'));

        $reversed = $this->poster()->reverse($posted, CarbonImmutable::parse('2026-08-01 12:00:00'));

        $reversal = $reversed->reversalLedgerTransaction;
        $this->assertNotNull($reversal);
        $this->assertSame([CommissionStatus::Reversed, '2026-08-02 10:00:00', $original->id], [$reversed->status, $reversed->reversed_at?->format('Y-m-d H:i:s'), $reversed->ledger_transaction_id]);
        $this->assertSame(
            [$original->id, 'commission', 'commission', $posted->id, "commission.reverse.{$posted->id}", '2026-08-01 12:00:00'],
            [$reversal->reversal_of_id, $reversal->type, $reversal->source_type, $reversal->source_id, $reversal->idempotency_key, $reversal->occurred_at->format('Y-m-d H:i:s')],
        );
        $this->assertSame('0', $this->balances()->forWallet($wallet)->value());
        $this->assertSame('0', $this->balances()->forAccount($this->run->sourceAccount)->value());
        $this->assertEquals($originalRows, [(array) DB::table('mlm_ledger_transactions')->where('id', $original->id)->first(), DB::table('mlm_ledger_postings')->where('ledger_transaction_id', $original->id)->orderBy('id')->get()->all()]);
    }

    public function test_reversing_again_at_the_same_moment_returns_the_commission_and_at_another_is_refused(): void
    {
        $posted = $this->poster()->post($this->approved($this->commissionOf($this->alice)));
        $reversed = $this->poster()->reverse($posted, CarbonImmutable::parse('2026-08-01 12:00:00'));
        $before = $this->ledgerRows();

        $again = $this->poster()->reverse($posted, CarbonImmutable::parse('2026-08-01 19:00:00', 'Asia/Jakarta'));
        $this->assertSame($reversed->reversal_ledger_transaction_id, $again->reversal_ledger_transaction_id);

        try {
            $this->poster()->reverse($posted, CarbonImmutable::parse('2026-08-02 12:00:00'));
            $this->fail('A second reversal was accepted.');
        } catch (InvalidCommissionPosting $exception) {
            $this->assertStringContainsString('was already reversed at 2026-08-01 12:00:00; a reversal at 2026-08-02 12:00:00 is another request', $exception->getMessage());
        }

        $this->assertSame($before, $this->ledgerRows());
        $this->assertSame(1, LedgerTransaction::query()->whereNotNull('reversal_of_id')->count());
    }

    public function test_a_ledger_failure_while_reversing_leaves_the_commission_posted(): void
    {
        $posted = $this->poster()->post($this->approved($this->commissionOf($this->alice)));
        $before = $this->ledgerRows();
        $this->failInsertsInto('mlm_ledger_postings');

        try {
            $this->poster()->reverse($posted, CarbonImmutable::parse('2026-08-01'));
            $this->fail('The commission was reversed although the ledger failed.');
        } catch (RuntimeException) {
        }

        $this->assertSame(CommissionStatus::Posted, $posted->refresh()->status);
        $this->assertNull($posted->reversal_ledger_transaction_id);
        $this->assertSame($before, $this->ledgerRows());
    }

    public function test_the_wallet_already_open_is_the_one_credited(): void
    {
        $wallet = $this->wallets()->open($this->alice, 'IDR');

        $this->poster()->post($this->approved($this->commissionOf($this->alice)));

        $this->assertSame(1, Wallet::query()->count());
        $this->assertSame(1, LedgerAccount::query()->whereNotNull('wallet_id')->count());
        $this->assertSame('125.000001', $this->balances()->forWallet($wallet)->value());
    }

    private function commissionOf(Member $member): Commission
    {
        return Commission::query()->where('calculation_run_id', $this->run->id)->where('member_id', $member->id)->sole();
    }
}
