<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Models\CalculationRun;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanComponent;
use PandaBear\Mlm\Models\VolumeEntry;
use PandaBear\Mlm\Planning\Rules\RuleDefinition;

/**
 * Components of the built-in fixed-award strategies, and the history they
 * read — sponsorships and business entries at chosen moments — built the
 * supported way.
 *
 * Uses BuildsCommissions, BuildsGenealogies, BuildsLedgers,
 * BuildsPlanDefinitions and RecordsVolume.
 */
trait BuildsFixedCommissions
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function directParameters(array $overrides = []): array
    {
        return ['volume_type' => 'sales', 'source_type' => 'order', 'minimum_quantity' => '100', 'amount' => '10', ...$overrides];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function unilevelParameters(array $overrides = []): array
    {
        return [
            'volume_type' => 'sales',
            'source_type' => 'order',
            'minimum_quantity' => '100',
            'levels' => [['depth' => 1, 'amount' => '10'], ['depth' => 2, 'amount' => '5'], ['depth' => 3, 'amount' => '2']],
            ...$overrides,
        ];
    }

    /**
     * A validated commission component of a built-in strategy, its source
     * account open.
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, RuleDefinition>  $rules
     */
    protected function fixedComponent(string $strategy, array $parameters, Plan $plan, array $rules = []): PlanComponent
    {
        return $this->commissionComponent(['strategy' => $strategy, 'parameters' => $parameters], $plan, $rules);
    }

    /**
     * `$sponsor` sponsors `$member`, effective at `$at`.
     */
    protected function sponsorAt(Member $member, Member $sponsor, string $at): void
    {
        $this->travelTo(CarbonImmutable::parse($at));
        $this->genealogy()->assignSponsor($member, $sponsor);
        $this->travelBack();
    }

    protected function sale(Member $member, string $quantity, string $at, string $key, string $type = 'sales', string $sourceType = 'order'): VolumeEntry
    {
        return $this->record($member, $quantity, $key, $type, $sourceType, strtoupper($key), CarbonImmutable::parse($at));
    }

    protected function monthly(PlanComponent $component, string $month, string $key = ''): CalculationRun
    {
        $from = CarbonImmutable::parse("{$month}-01 00:00:00");

        return $this->calculate($component, $from->format('Y-m-d H:i:s'), $from->addMonth()->format('Y-m-d H:i:s'), $key === '' ? "run:{$month}" : $key);
    }

    /**
     * Each commission of the run, in candidate key order, as recipient
     * member code => [amount, depth, source entry id, earned at].
     *
     * @return list<array{string, string, int, string, string}>
     */
    protected function awards(CalculationRun $run): array
    {
        return $run->commissions()->get()->map(static fn (Commission $commission): array => [
            Member::query()->findOrFail($commission->member_id)->member_code,
            $commission->amount->value(),
            $commission->trace['recipient']['depth'],
            $commission->trace['source']['volume_entry_id'],
            $commission->earned_at->format('Y-m-d H:i:s'),
        ])->all();
    }

    /**
     * Every row the strategies may read, to prove they write none of it.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    protected function readableRows(): array
    {
        $rows = [];

        foreach (['mlm_members', 'mlm_sponsor_edges', 'mlm_placement_edges', 'mlm_genealogy_paths', 'mlm_volume_entries', 'mlm_plan_versions', 'mlm_plan_components', 'mlm_plan_rules', 'mlm_wallets', 'mlm_ledger_accounts', 'mlm_ledger_transactions', 'mlm_ledger_postings'] as $table) {
            $rows[$table] = DB::table($table)->get()->map(static fn (object $row): array => (array) $row)->sortBy(static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR))->values()->all();
        }

        return $rows;
    }
}
