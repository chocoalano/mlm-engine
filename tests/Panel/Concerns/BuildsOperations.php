<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Panel\Concerns;

use Carbon\CarbonImmutable;
use PandaBear\Mlm\Models\CommissionPeriod;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Period\CommissionPeriodManager;
use PandaBear\Mlm\Tests\Concerns\BuildsCommissions;
use PandaBear\Mlm\Tests\Concerns\BuildsFixedCommissions;
use PandaBear\Mlm\Tests\Concerns\BuildsGenealogies;
use PandaBear\Mlm\Tests\Concerns\BuildsLedgers;
use PandaBear\Mlm\Tests\Concerns\BuildsPayouts;
use PandaBear\Mlm\Tests\Concerns\BuildsPlanDefinitions;
use PandaBear\Mlm\Tests\Concerns\RecordsVolume;

/**
 * An operating program, built only through the domain services: members
 * sponsored by ALICE, an active plan version paying a fixed direct-sponsor
 * commission, the account that funds it, and periods over it.
 */
trait BuildsOperations
{
    use BuildsCommissions;
    use BuildsFixedCommissions;
    use BuildsGenealogies;
    use BuildsLedgers;
    use BuildsPayouts;
    use BuildsPlanDefinitions;
    use RecordsVolume;

    protected Program $program;

    /** @var array<string, Member> */
    protected array $team = [];

    protected LedgerAccount $source;

    protected function operatingProgram(string ...$sponsored): void
    {
        $this->program = Program::factory()->create();
        $this->team = $this->members($this->program, 'ALICE', ...$sponsored);

        foreach ($sponsored as $code) {
            $this->sponsorAt($this->team[$code], $this->team['ALICE'], '2026-01-01 00:00:00');
        }

        $this->source = $this->systemAccounts()->openSystemAccount($this->program, 'IDR', 'commission.payable');
    }

    /**
     * An active version of a new plan of the program, with one commission
     * component per strategy.
     *
     * @param  array<string, string>  $components  key => strategy
     */
    protected function activeVersion(array $components = ['direct' => 'direct-sponsor.fixed']): PlanVersion
    {
        $draft = $this->draft(Plan::factory()->for($this->program)->create());

        foreach ($components as $key => $strategy) {
            $parameters = match ($strategy) {
                'direct-sponsor.fixed' => ['volume_type' => 'sales', 'source_type' => 'order', 'minimum_quantity' => '1', 'amount' => '10'],
                default => [],
            };

            $this->editor()->addComponent($draft, $key, 'commission.strategy', ucfirst($key), $this->commissionParameters(['strategy' => $strategy, 'parameters' => $parameters]));
        }

        $this->lifecycle()->markValidated($draft);
        $this->lifecycle()->publish($draft->refresh());
        $this->lifecycle()->activate($draft->refresh());

        return PlanVersion::query()->findOrFail($draft->id);
    }

    protected function openPeriod(PlanVersion $version, string $from = '2026-01-01', string $until = '2026-02-01', string $release = '2026-02-15', ?string $key = null): CommissionPeriod
    {
        return $this->app->make(CommissionPeriodManager::class)->create(
            $this->program,
            $version,
            $this->source,
            CarbonImmutable::parse($from),
            CarbonImmutable::parse($until),
            CarbonImmutable::parse($release),
            $key ?? "period:{$from}",
        );
    }
}
