<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Commission\CommissionStatus;
use PandaBear\Mlm\Exceptions\InvalidCommissionTransition;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Tests\Concerns\BuildsCommissions;
use PandaBear\Mlm\Tests\Concerns\BuildsLedgers;
use PandaBear\Mlm\Tests\Concerns\BuildsPlanDefinitions;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Review, one commission at a time: CALCULATED, PENDING, APPROVED, or
 * CANCELLED before any money moves — decided from the stored status.
 */
final class CommissionLifecycleTest extends DatabaseTestCase
{
    use BuildsCommissions;
    use BuildsLedgers;
    use BuildsPlanDefinitions;

    public function test_a_commission_is_reviewed_then_approved(): void
    {
        $commission = $this->commission();

        $this->travelTo(CarbonImmutable::parse('2026-07-02 09:00:00'));
        $pending = $this->commissionLifecycle()->markPending($commission);
        $this->travelTo(CarbonImmutable::parse('2026-07-03 10:00:00'));
        $approved = $this->commissionLifecycle()->approve($pending);

        $this->assertSame(CommissionStatus::Pending, $pending->status);
        $this->assertSame(
            [CommissionStatus::Approved, '2026-07-02 09:00:00', '2026-07-03 10:00:00', null, null],
            [$approved->status, $approved->pending_at?->format('Y-m-d H:i:s'), $approved->approved_at?->format('Y-m-d H:i:s'), $approved->posted_at, $approved->cancelled_at],
        );
        $this->assertSame([$commission->amount->value(), $commission->candidate_key], [$approved->amount->value(), $approved->candidate_key]);
    }

    /**
     * @return array<string, array{Closure(self, Commission): Commission}>
     */
    public static function cancellable(): array
    {
        return [
            'calculated' => [static fn (self $test, Commission $commission): Commission => $commission],
            'pending' => [static fn (self $test, Commission $commission): Commission => $test->commissionLifecycle()->markPending($commission)],
            'approved' => [static fn (self $test, Commission $commission): Commission => $test->approved($commission)],
        ];
    }

    /**
     * @param  Closure(self, Commission): Commission  $reach
     */
    #[DataProvider('cancellable')]
    public function test_a_commission_not_yet_posted_can_be_cancelled_and_moves_no_money(Closure $reach): void
    {
        $commission = $reach($this, $this->commission());
        $before = $this->ledgerRows();

        $cancelled = $this->commissionLifecycle()->cancel($commission);

        $this->assertSame(CommissionStatus::Cancelled, $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertNull($cancelled->ledger_transaction_id);
        $this->assertSame($before, $this->ledgerRows());

        foreach ([
            fn () => $this->commissionLifecycle()->markPending($cancelled),
            fn () => $this->commissionLifecycle()->approve($cancelled),
            fn () => $this->commissionLifecycle()->cancel($cancelled),
            fn () => $this->poster()->post($cancelled),
            fn () => $this->poster()->reverse($cancelled, CarbonImmutable::parse('2026-08-01')),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A cancelled commission moved on.');
            } catch (InvalidCommissionTransition $exception) {
                $this->assertStringContainsString('is cancelled and cannot become', $exception->getMessage());
            }
        }

        $this->assertSame($before, $this->ledgerRows());
    }

    /**
     * @return array<string, array{Closure(self, Commission): Commission, Closure(self, Commission): mixed, string}>
     */
    public static function invalidTransitions(): array
    {
        return [
            'approving a calculated commission' => [
                static fn (self $test, Commission $commission): Commission => $commission,
                static fn (self $test, Commission $commission): mixed => $test->commissionLifecycle()->approve($commission),
                'is calculated and cannot become approved; from calculated it can only become pending or cancelled',
            ],
            'marking a pending commission pending again' => [
                static fn (self $test, Commission $commission): Commission => $test->commissionLifecycle()->markPending($commission),
                static fn (self $test, Commission $commission): mixed => $test->commissionLifecycle()->markPending($commission),
                'is pending and cannot become pending',
            ],
            'sending an approved commission back to pending' => [
                static fn (self $test, Commission $commission): Commission => $test->approved($commission),
                static fn (self $test, Commission $commission): mixed => $test->commissionLifecycle()->markPending($commission),
                'is approved and cannot become pending',
            ],
            'posting a calculated commission' => [
                static fn (self $test, Commission $commission): Commission => $commission,
                static fn (self $test, Commission $commission): mixed => $test->poster()->post($commission),
                'is calculated and cannot become posted',
            ],
            'posting a pending commission' => [
                static fn (self $test, Commission $commission): Commission => $test->commissionLifecycle()->markPending($commission),
                static fn (self $test, Commission $commission): mixed => $test->poster()->post($commission),
                'is pending and cannot become posted',
            ],
            'reversing an approved commission' => [
                static fn (self $test, Commission $commission): Commission => $test->approved($commission),
                static fn (self $test, Commission $commission): mixed => $test->poster()->reverse($commission, CarbonImmutable::parse('2026-08-01')),
                'is approved and cannot become reversed',
            ],
        ];
    }

    /**
     * @param  Closure(self, Commission): Commission  $reach
     * @param  Closure(self, Commission): mixed  $attempt
     */
    #[DataProvider('invalidTransitions')]
    public function test_only_the_next_steps_are_allowed(Closure $reach, Closure $attempt, string $reason): void
    {
        $commission = $reach($this, $this->commission());
        $before = [$this->ledgerRows(), DB::table('mlm_commissions')->get()->all()];

        try {
            $attempt($this, $commission);
            $this->fail('An invalid transition was allowed.');
        } catch (InvalidCommissionTransition $exception) {
            $this->assertStringContainsString($reason, $exception->getMessage());
        }

        $this->assertEquals($before, [$this->ledgerRows(), DB::table('mlm_commissions')->get()->all()]);
    }

    public function test_the_stored_status_decides_not_the_instance(): void
    {
        $commission = $this->commission();
        $stale = Commission::query()->findOrFail($commission->id);
        $this->approved($commission);

        // The instance still says CALCULATED; the row is APPROVED.
        $this->assertSame(CommissionStatus::Calculated, $stale->status);

        $this->expectException(InvalidCommissionTransition::class);
        $this->expectExceptionMessage('is approved and cannot become pending');

        $this->commissionLifecycle()->markPending($stale);
    }

    public function test_a_status_claimed_in_memory_does_not_allow_a_move(): void
    {
        $commission = $this->commission();
        $commission->forceFill(['status' => CommissionStatus::Pending]);

        $this->expectException(InvalidCommissionTransition::class);
        $this->expectExceptionMessage('is calculated and cannot become approved');

        $this->commissionLifecycle()->approve($commission);
    }

    private function commission(): Commission
    {
        $component = $this->commissionComponent();
        Member::factory()->for($component->planVersion->plan->program)->create();

        return $this->calculate($component)->commissions()->sole();
    }
}
