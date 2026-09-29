<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Binary\BinarySide;
use PandaBear\Mlm\Models\CalculationRun;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\Plan;
use PandaBear\Mlm\Models\PlanComponent;
use PandaBear\Mlm\Volume\Quantity;

/**
 * Binary pairing components, binary trees and their state, built and read
 * the supported way.
 *
 * Uses BuildsCommissions, BuildsFixedCommissions, BuildsGenealogies,
 * BuildsLedgers, BuildsPlanDefinitions and RecordsVolume.
 */
trait BuildsBinaryPairing
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function fixedPairing(array $overrides = []): array
    {
        return ['volume_type' => 'sales', 'pair_quantity' => '100', 'amount_per_pair' => '10', ...$overrides];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function proportionalPairing(array $overrides = []): array
    {
        return ['volume_type' => 'sales', 'pair_quantity' => '100', 'unit_amount' => '0.1', 'rounding' => 'half_even', ...$overrides];
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    protected function pairingComponent(array $parameters, Plan $plan, string $strategy = 'binary.pairing.fixed'): PlanComponent
    {
        return $this->fixedComponent($strategy, $parameters, $plan);
    }

    /**
     * `$child` on `$side` of `$parent`, from `$at`.
     *
     * @param  array<string, Member>  $members
     */
    protected function binaryAt(array $members, string $parent, string $child, BinarySide $side, string $at = '2026-01-01 00:00:00'): void
    {
        $this->travelTo(CarbonImmutable::parse($at));
        $this->binary()->place($members[$child], $members[$parent], $side);
        $this->travelBack();
    }

    protected function pair(PlanComponent $component, string $from, string $until, ?string $key = null): CalculationRun
    {
        return $this->calculate($component, $from, $until, $key ?? "pair:{$from}");
    }

    /**
     * The run's pairing results by member code: every stored column but the
     * keys and timestamps, and whether it earned a commission.
     *
     * @return array<string, array<string, string|bool>>
     */
    protected function pairingResults(CalculationRun $run): array
    {
        $codes = Member::query()->pluck('member_code', 'id');
        $results = [];

        foreach (DB::table('mlm_binary_pairing_results')->where('calculation_run_id', $run->id)->get() as $row) {
            $results[$codes[$row->member_id]] = [
                'left' => "{$row->left_carry_before} + {$row->left_added} - {$row->left_reversed} = {$row->left_available} -> {$row->left_carry_after}",
                'right' => "{$row->right_carry_before} + {$row->right_added} - {$row->right_reversed} = {$row->right_available} -> {$row->right_carry_after}",
                'pairs' => "{$row->pair_count} x {$row->pair_quantity} = {$row->consumed_quantity}",
                'commission' => $row->commission_id !== null,
                // Only when a correction gave carry back: then available is
                // before + added + restored - reversed.
                ...($row->left_restored === '0' && $row->right_restored === '0' ? [] : ['restored' => "left {$row->left_restored}, right {$row->right_restored}"]),
            ];
        }

        ksort($results);

        return $results;
    }

    /**
     * Every carry lot of the component, by owner code, side and source key:
     * "quantity/remaining", with "reversed" when a reversal took it back.
     *
     * @return array<string, string>
     */
    protected function carryLots(PlanComponent $component): array
    {
        $codes = Member::query()->pluck('member_code', 'id');
        $keys = DB::table('mlm_volume_entries')->pluck('idempotency_key', 'id');
        $lots = [];

        foreach (DB::table('mlm_binary_carry_lots')->where('plan_component_id', $component->id)->get() as $lot) {
            $lots["{$codes[$lot->member_id]} {$lot->side} {$keys[$lot->source_volume_entry_id]}"] = sprintf(
                '%s/%s%s',
                $this->millionths($lot->quantity_millionths),
                $this->millionths($lot->remaining_millionths),
                $lot->reversed_by_volume_entry_id === null ? '' : ' reversed',
            );
        }

        ksort($lots);

        return $lots;
    }

    /**
     * What each result of the run consumed, lot by lot, in stored order:
     * "owner side source quantity".
     *
     * @return list<string>
     */
    protected function allocationsOf(CalculationRun $run): array
    {
        $codes = Member::query()->pluck('member_code', 'id');
        $keys = DB::table('mlm_volume_entries')->pluck('idempotency_key', 'id');

        return DB::table('mlm_binary_pairing_allocations as allocations')
            ->join('mlm_binary_pairing_results as results', 'results.id', '=', 'allocations.binary_pairing_result_id')
            ->join('mlm_binary_carry_lots as lots', 'lots.id', '=', 'allocations.binary_carry_lot_id')
            ->where('results.calculation_run_id', $run->id)
            ->orderBy('allocations.id')
            ->get(['results.member_id', 'allocations.side', 'lots.source_volume_entry_id', 'allocations.quantity_millionths'])
            ->map(fn (object $row): string => "{$codes[$row->member_id]} {$row->side} {$keys[$row->source_volume_entry_id]} {$this->millionths($row->quantity_millionths)}")
            ->all();
    }

    /**
     * Everything binary pairing stores, exactly.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    protected function pairingState(): array
    {
        $state = [];

        foreach (['mlm_binary_pairing_cursors', 'mlm_binary_carry_lots', 'mlm_binary_pairing_results', 'mlm_binary_pairing_allocations', 'mlm_binary_pairing_corrections', 'mlm_binary_pairing_restorations', 'mlm_calculation_runs', 'mlm_commissions'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
        }

        return $state;
    }

    private function millionths(int|string $millionths): string
    {
        return Quantity::fromMillionths($millionths)->value();
    }
}
