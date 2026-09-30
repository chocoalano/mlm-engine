<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Panel;

use Illuminate\Testing\TestResponse;
use PandaBear\Mlm\Commission\CommissionCalculationContext;
use PandaBear\Mlm\Commission\CommissionStatus;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\CommissionPeriod;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Resources\Periods\CommissionPeriodResource;
use PandaBear\Mlm\Period\CommissionPeriodCalculator;
use PandaBear\Mlm\Period\CommissionPeriodFinalizer;
use PandaBear\Mlm\Period\CommissionPeriodReleaser;
use PandaBear\Mlm\Period\CommissionPeriodStatus;
use PandaBear\Mlm\Tests\Panel\Concerns\BuildsOperations;
use RuntimeException;

/**
 * Period lifecycle actions from the panel (ADR-031): each is one call to its
 * period service, over the panel's own action endpoints, and a refusal is
 * the service's reason shown to the operator with nothing changed.
 */
final class PeriodPanelActionsTest extends PanelTestCase
{
    use BuildsOperations;

    private CommissionPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operatingProgram('BOB', 'CAROL');
        $this->sale($this->team['BOB'], '150', '2026-01-10', 'order:1');
        $this->sale($this->team['CAROL'], '150', '2026-01-11', 'order:2');
        $this->period = $this->openPeriod($this->activeVersion());
    }

    public function test_calculate_finalize_and_release_each_go_through_their_period_service(): void
    {
        $this->grantAll();
        $resolved = $this->spyOn([CommissionPeriodCalculator::class, CommissionPeriodFinalizer::class, CommissionPeriodReleaser::class]);

        $this->act('record', 'calculate')->assertRedirect()->assertSessionHas('success', 'Commission period calculated.');

        $this->assertSame(CommissionPeriodStatus::Calculated, $this->period->refresh()->status);
        $this->assertSame(1, $resolved[CommissionPeriodCalculator::class]);
        $this->assertSame(2, Commission::query()->count());

        Commission::query()->get()->each(fn (Commission $commission) => $this->approved($commission));
        $ledger = $this->ledgerRows();

        $this->act('infolist', 'finalize')->assertSessionHas('success', 'Commission period finalized.');

        $this->assertSame(CommissionPeriodStatus::Finalized, $this->period->refresh()->status);
        $this->assertSame([CommissionStatus::Held], Commission::query()->pluck('status')->unique()->values()->all());
        $this->assertSame(1, $resolved[CommissionPeriodFinalizer::class]);

        $this->submit('release', ['released_at' => '2026-02-15 00:00:00'])->assertSessionHas('success', 'Commission period released.');

        $this->assertSame(CommissionPeriodStatus::Released, $this->period->refresh()->status);
        $this->assertSame('2026-02-15 00:00:00', $this->period->released_at?->format('Y-m-d H:i:s'));
        $this->assertSame([CommissionStatus::Available], Commission::query()->pluck('status')->unique()->values()->all());
        $this->assertSame(1, $resolved[CommissionPeriodReleaser::class]);

        // Finalizing and releasing moved no money.
        $this->assertSame($ledger, $this->ledgerRows());
    }

    public function test_finalizing_with_unreviewed_commissions_shows_the_services_reason_and_changes_nothing(): void
    {
        $this->grantAll();
        $this->app->make(CommissionPeriodCalculator::class)->calculate($this->period);
        [$first] = Commission::query()->orderBy('id')->get()->all();
        $this->approved($first);

        $this->act('record', 'finalize')
            ->assertRedirect()
            ->assertSessionMissing('success')
            ->assertSessionHas('error', fn (string $message): bool => str_starts_with($message, 'Finalize was refused:')
                && str_contains($message, 'but it has 1 calculated'));

        $this->assertSame(CommissionPeriodStatus::Calculated, $this->period->refresh()->status);
        $this->assertSame([CommissionStatus::Approved, CommissionStatus::Calculated], Commission::query()->orderBy('id')->pluck('status')->all());
    }

    public function test_releasing_before_the_release_moment_is_refused_and_changes_nothing(): void
    {
        $this->grantAll();
        $this->app->make(CommissionPeriodCalculator::class)->calculate($this->period);
        Commission::query()->get()->each(fn (Commission $commission) => $this->approved($commission));
        $this->app->make(CommissionPeriodFinalizer::class)->finalize($this->period);

        $this->app->setLocale('id');

        $this->submit('release', ['released_at' => '2026-02-14 23:59:59'])
            ->assertSessionHas('error', fn (string $message): bool => str_starts_with($message, 'Rilis ditolak:')
                && str_contains($message, 'is released no earlier than 2026-02-15 00:00:00'));

        $this->assertSame(CommissionPeriodStatus::Finalized, $this->period->refresh()->status);
        $this->assertSame([CommissionStatus::Held], Commission::query()->pluck('status')->unique()->values()->all());
    }

    public function test_a_view_only_operator_sees_no_period_action_and_is_refused_one(): void
    {
        $this->grant(MlmPermission::PERIODS_VIEW);

        panelRecordActions(CommissionPeriodResource::class)->assertHidden('calculate', $this->period)->assertCanNotRun('calculate', $this->period);
        panelInfolistActions(CommissionPeriodResource::class)->assertHidden('calculate', $this->period);

        $this->act('record', 'calculate')->assertForbidden();
        $this->act('infolist', 'calculate')->assertForbidden();

        $this->assertSame(CommissionPeriodStatus::Open, $this->period->refresh()->status);
        $this->assertNull($this->period->input_closed_at);
    }

    public function test_an_operate_capability_alone_does_not_open_the_periods(): void
    {
        $this->grant(MlmPermission::PERIODS_OPERATE);

        $this->withHeaders(['X-Inertia' => 'true'])->get('/mlm/mlm-commission-periods')->assertForbidden();
    }

    public function test_each_step_is_offered_only_from_the_status_it_starts_from(): void
    {
        $this->grantAll();
        $actions = panelRecordActions(CommissionPeriodResource::class);

        $actions->assertVisible('calculate', $this->period)->assertHidden('finalize', $this->period)->assertHidden('release', $this->period);

        $this->app->make(CommissionPeriodCalculator::class)->calculate($this->period);
        $this->period->refresh();

        $actions->assertHidden('calculate', $this->period)->assertVisible('finalize', $this->period)->assertHidden('release', $this->period);
    }

    public function test_a_period_whose_calculation_failed_reads_as_input_closed_not_as_open(): void
    {
        $this->grantAll();
        $strategy = $this->scriptedStrategy();
        $strategy->script = static fn (CommissionCalculationContext $context): iterable => throw new RuntimeException('Component failed.');
        $stalled = $this->openPeriod($this->activeVersion(['scripted' => 'test.scripted']), '2026-03-01', '2026-04-01', '2026-04-15');

        try {
            $this->app->make(CommissionPeriodCalculator::class)->calculate($stalled);
            $this->fail('The scripted component did not fail.');
        } catch (RuntimeException) {
        }

        $stalled->refresh();
        $this->assertSame(CommissionPeriodStatus::Open, $stalled->status);
        $this->assertNotNull($stalled->input_closed_at);

        $row = panelTable(CommissionPeriodResource::class)->row($stalled);
        $this->assertSame(['value' => 'open_input_closed', 'label' => 'Open — input closed', 'color' => 'warning'], $row['cells']['status']);
        $this->assertSame(['value' => 'open', 'label' => 'Open', 'color' => 'info'], panelTable(CommissionPeriodResource::class)->row($this->period->refresh())['cells']['status']);

        $page = $this->withHeaders(['X-Inertia' => 'true'])->get("/mlm/mlm-commission-periods/{$stalled->id}")->assertOk();
        $this->assertStringContainsString('A calculation was attempted and did not finish.', (string) $page->getContent());

        // It can be resumed from the panel: Calculate is still offered.
        panelRecordActions(CommissionPeriodResource::class)->assertVisible('calculate', $stalled);
    }

    public function test_the_list_explains_that_no_period_action_moves_money(): void
    {
        $this->grantAll();

        $response = $this->withHeaders(['X-Inertia' => 'true'])->get('/mlm/mlm-commission-periods')->assertOk();

        $this->assertSame('Nothing on this screen moves money', $response->json('props.table.callouts.0.heading'));
        $this->assertSame('panel/resources/Index', $response->json('component'));
    }

    /**
     * Counts how often each class is resolved from the container.
     *
     * @param  list<class-string>  $classes
     * @return \ArrayObject<class-string, int>
     */
    private function spyOn(array $classes): \ArrayObject
    {
        $resolved = new \ArrayObject(array_fill_keys($classes, 0));

        foreach ($classes as $class) {
            $this->app->resolving($class, static function () use ($resolved, $class): void {
                $resolved[$class]++;
            });
        }

        return $resolved;
    }

    private function act(string $endpoint, string $action): TestResponse
    {
        return $this->post("/mlm/actions/{$endpoint}", [
            'resource' => 'mlm-commission-periods',
            'action' => $action,
            'record' => $this->period->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function submit(string $action, array $data): TestResponse
    {
        return $this->post('/mlm/actions/form', [
            'resource' => 'mlm-commission-periods',
            'action' => $action,
            'scope' => 'record',
            'record' => $this->period->id,
            ...$data,
        ]);
    }
}
