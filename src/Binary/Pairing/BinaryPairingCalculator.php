<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Binary\Pairing;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Binary\BinarySide;
use PandaBear\Mlm\Binary\Correction\BinaryAllocationHistory;
use PandaBear\Mlm\Binary\Correction\BinaryConsumedReversalPlanner;
use PandaBear\Mlm\Commission\CommissionCalculationContext;
use PandaBear\Mlm\Commission\CommissionCandidate;
use PandaBear\Mlm\Commission\StatefulCommissionCalculation;
use PandaBear\Mlm\Exceptions\CorruptBinaryPlacement;
use PandaBear\Mlm\Exceptions\InvalidBinaryPairingRange;
use PandaBear\Mlm\Exceptions\InvalidBinaryPairingState;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Support\EffectiveMoment;
use PandaBear\Mlm\Volume\Quantity;

/**
 * @internal
 *
 * One binary pairing run of one component (ADR-023), calculated from what
 * is stored and planned as data — it writes nothing:
 *
 * 1. The component's cursor decides where its state stands: a first run
 *    starts it at `from`; every later run starts exactly where the last
 *    ended.
 * 2. Every original of the component's volume type in [from, until) becomes
 *    a carry lot in each binary leg it fell in, as the binary tree stood at
 *    its own moment. An original already reversed before `until` is taken
 *    back at once. A reversal in the range of an original an earlier run
 *    took in takes back what its lots still hold — and undoes the earlier
 *    pairs they fed, giving the other side's quantity back to carry
 *    (ADR-025).
 * 3. Then, once, at the run's close: for every binary member with carry or
 *    change, available = carry before + added + given back − taken back on
 *    each side,
 *    pairs = ⌊min(left, right) ÷ pair quantity⌋, and each side gives up
 *    pairs × pair quantity, its oldest carry first.
 * 4. A member whose pairs earn a positive award gets one candidate, earned
 *    at `until`.
 */
final readonly class BinaryPairingCalculator
{
    private const CURSORS = 'mlm_binary_pairing_cursors';

    private const LOTS = 'mlm_binary_carry_lots';

    private const MEMBERS = 'mlm_members';

    /**
     * Lots read per query while consuming carry.
     */
    private const CHUNK = 500;

    public function __construct(
        private BinaryPairingSourceEvents $events,
        private BinaryConsumedReversalPlanner $corrections,
    ) {}

    public function calculate(string $strategy, CommissionCalculationContext $context, BinaryPairingParameters $parameters): StatefulCommissionCalculation
    {
        $component = $context->planComponentId ?? throw InvalidBinaryPairingRange::withoutComponent($strategy);
        $db = DB::connection($context->connection);
        $program = (string) $context->program->getKey();
        [$from, $until] = [$context->from, $context->until];

        [$cursor, $startedAt] = $this->cursor($db, $component, $program, $from, $until);

        $plan = new BinaryPairingPlan($component, $program, $cursor, $startedAt, $until);

        $this->takeInOriginals($db, $plan, $parameters->volumeType, $from, $until);
        $this->takeBackReversals($db, $plan, $parameters->volumeType, $from, $until, $startedAt);
        $this->carryBefore($db, $plan);

        $pairQuantity = PairingArithmetic::millionths($parameters->pairQuantity);
        $candidates = [];
        $recipients = [];

        foreach ($plan->members() as $member) {
            $left = $plan->available($member, BinarySide::Left);
            $right = $plan->available($member, BinarySide::Right);
            $pairCount = PairingArithmetic::floorDivide(PairingArithmetic::min($left, $right), $pairQuantity);
            $consumed = PairingArithmetic::multiply($pairCount, $pairQuantity);

            if ($consumed !== '0') {
                foreach (BinarySide::cases() as $side) {
                    $this->consume($db, $plan, $member, $side, $consumed);
                }
            }

            $plan->settle($member, $parameters->pairQuantity->value(), $pairCount, $consumed);

            if ($pairCount !== '0') {
                $recipients[] = $member;
            }
        }

        $members = $this->members($db, $program, $recipients);

        foreach ($recipients as $member) {
            [$amount, $calculation] = $parameters->award->award($plan->pairCount($member), Quantity::fromMillionths($plan->consumed($member)));

            // Paired, and recorded as paired, even when the award rounds to
            // nothing: then there is simply no commission.
            if ($amount->isZero()) {
                continue;
            }

            $key = 'binary-pairing:'.$member;
            $plan->earns($member, $key);

            $candidates[] = new CommissionCandidate(
                key: $key,
                member: $members[$member],
                amount: $amount,
                earnedAt: $until,
                trace: [
                    'strategy' => $strategy,
                    'binary_member_id' => $member,
                    'run' => ['from' => $from->format('Y-m-d H:i:s'), 'until' => $until->format('Y-m-d H:i:s')],
                    'pairing' => [
                        'pair_quantity' => $parameters->pairQuantity->value(),
                        'pair_count' => $plan->pairCount($member),
                        'consumed_quantity' => PairingArithmetic::quantity($plan->consumed($member)),
                    ],
                    'carry' => $plan->carryTrace($member),
                    'calculation' => $calculation,
                ],
            );
        }

        return new StatefulCommissionCalculation($candidates, new BinaryPairingStateTransition($plan));
    }

    /**
     * The stored cursor, and when this component's state began.
     *
     * @return array{object|null, CarbonImmutable}
     */
    private function cursor(Connection $db, string $component, string $program, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $cursor = $db->table(self::CURSORS)->where('plan_component_id', $component)->first();

        if ($cursor === null) {
            return [null, $from];
        }

        if ($cursor->program_id !== $program) {
            throw InvalidBinaryPairingState::corrupt($component, "its cursor belongs to program [{$cursor->program_id}], not [{$program}]");
        }

        $through = $this->moment($cursor->through_at);
        $range = [$component, $from->format('Y-m-d H:i:s'), $until->format('Y-m-d H:i:s'), $through->format('Y-m-d H:i:s')];

        if ($from->lessThan($through)) {
            throw InvalidBinaryPairingRange::stale(...$range);
        }

        if ($from->greaterThan($through)) {
            throw InvalidBinaryPairingRange::gap(...$range);
        }

        return [$cursor, $this->moment($cursor->started_at)];
    }

    /**
     * New carry: every original in the range, in each leg it fell in then.
     */
    private function takeInOriginals(Connection $db, BinaryPairingPlan $plan, string $type, CarbonImmutable $from, CarbonImmutable $until): void
    {
        foreach ($this->events->originals($db, $plan->program, $type, $from, $until) as $row) {
            if ($row->edge_parent_id !== $row->anchor_id) {
                throw CorruptBinaryPlacement::parentMismatch($row->position_id, $row->anchor_id, $row->edge_id, $row->edge_parent_id);
            }

            $side = BinarySide::parse($row->side) ?? throw CorruptBinaryPlacement::side($row->position_id, $row->side);

            // Reversed before this run ends — even at a moment an earlier run
            // covered, before the original was taken in: it never pairs.
            $reversal = $row->reversal_id !== null && $this->moment($row->reversal_effective_at)->lessThan($until)
                ? (string) $row->reversal_id
                : null;

            $plan->addLot($row->anchor_id, $side, (string) $row->entry_id, $this->moment($row->effective_at)->format('Y-m-d H:i:s'), (string) $row->quantity_millionths, $reversal);
        }

        $this->assertInProgram($db, $plan, $plan->anchors());
    }

    /**
     * Reversals in the range of originals an earlier run took in: each takes
     * back what its lots still hold and undoes the pairs they fed, in a
     * stable order — by the reversal's moment, then its id, then its
     * original's — so a pair both of whose sources are reversed is undone
     * once. An original before the component's state began was never taken
     * in; one in this run was handled with it; one after this run is not
     * yet.
     */
    private function takeBackReversals(Connection $db, BinaryPairingPlan $plan, string $type, CarbonImmutable $from, CarbonImmutable $until, CarbonImmutable $startedAt): void
    {
        $reversals = [...$this->events->reversals($db, $plan->program, $type, $from, $until, $startedAt, $from)];

        if ($reversals === []) {
            return;
        }

        usort($reversals, fn (object $a, object $b): int => [$this->moment($a->reversal_effective_at), (string) $a->reversal_id, (string) $a->original_id]
            <=> [$this->moment($b->reversal_effective_at), (string) $b->reversal_id, (string) $b->original_id]);

        $lots = [];

        foreach (array_chunk(array_map(static fn (object $row): string => (string) $row->original_id, $reversals), self::CHUNK) as $originals) {
            $rows = $db->table(self::LOTS)
                ->where('plan_component_id', $plan->component)
                ->where('program_id', $plan->program)
                ->whereIn('source_volume_entry_id', $originals)
                ->orderBy('member_id')
                ->orderBy('side')
                ->orderBy('id')
                ->get();

            foreach ($rows as $lot) {
                $lots[(string) $lot->source_volume_entry_id][] = $lot;
            }
        }

        // Only lots something was ever drawn from have pairs to undo.
        $drawn = [];

        foreach ($lots as $ofOriginal) {
            foreach ($ofOriginal as $lot) {
                if ((string) $lot->remaining_millionths !== (string) $lot->quantity_millionths) {
                    $drawn[] = (string) $lot->id;
                }
            }
        }

        $history = BinaryAllocationHistory::load($db, $drawn, opposites: true);

        foreach ($reversals as $reversal) {
            foreach ($lots[(string) $reversal->original_id] ?? [] as $lot) {
                if ($lot->reversed_by_volume_entry_id !== null) {
                    throw InvalidBinaryPairingState::corrupt($plan->component, "carry lot [{$lot->id}] was already taken back by [{$lot->reversed_by_volume_entry_id}]");
                }

                $side = BinarySide::parse($lot->side) ?? throw CorruptBinaryPlacement::side((string) $lot->id, $lot->side);
                $plan->storedLot((string) $lot->id, (string) $lot->member_id, $side, (string) $lot->source_effective_at, (string) $lot->source_volume_entry_id, (string) $lot->remaining_millionths);
                $this->corrections->undo($plan, $history, $lot, (string) $reversal->reversal_id, (string) $reversal->original_id);
                $plan->reverseStored((string) $lot->id, (string) $reversal->reversal_id);
            }
        }
    }

    /**
     * Every member's unpaired carry, by side, as it stands: the open lots
     * alone, summed exactly here — a member's carry may outgrow any
     * integer a database sums into.
     */
    private function carryBefore(Connection $db, BinaryPairingPlan $plan): void
    {
        $open = $db->table(self::LOTS)
            ->where('plan_component_id', $plan->component)
            ->where('program_id', $plan->program)
            ->where('remaining_millionths', '>', 0)
            ->select(['id', 'member_id', 'side', 'remaining_millionths'])
            ->cursor();

        foreach ($open as $lot) {
            $side = BinarySide::parse($lot->side) ?? throw CorruptBinaryPlacement::side((string) $lot->id, $lot->side);
            $plan->carry((string) $lot->member_id, $side, (string) $lot->remaining_millionths);
        }
    }

    /**
     * Takes `$quantity` from the member's carry on one side, oldest source
     * first — stored lots, then this run's — and records every lot it
     * draws on. A stored lot is taken as the run leaves it so far: nothing
     * of one a reversal took back, and all a correction gave back.
     */
    private function consume(Connection $db, BinaryPairingPlan $plan, string $member, BinarySide $side, string $quantity): void
    {
        $needed = $quantity;
        $revived = $plan->revived($member, $side);
        $last = null;

        // Stored lots are older than this run's: they came from earlier runs.
        // A side with no stored carry, and none given back, has none to read.
        while ($plan->carries($member, $side) || $revived !== []) {
            $lots = $db->table(self::LOTS)
                ->where('plan_component_id', $plan->component)
                ->where('program_id', $plan->program)
                ->where('member_id', $member)
                ->where('side', $side->value)
                ->where(static fn (Builder $open): Builder => $open
                    ->where('remaining_millionths', '>', 0)
                    ->when($revived !== [], static fn (Builder $query): Builder => $query->orWhereIn('id', array_keys($revived))))
                ->when($last !== null, static fn (Builder $query): Builder => $query->where(static fn (Builder $after): Builder => $after
                    ->where('source_effective_at', '>', $last->source_effective_at)
                    ->orWhere(static fn (Builder $same): Builder => $same
                        ->where('source_effective_at', $last->source_effective_at)
                        ->where('source_volume_entry_id', '>', $last->source_volume_entry_id))))
                ->orderBy('source_effective_at')
                ->orderBy('source_volume_entry_id')
                ->limit(self::CHUNK)
                ->get(['id', 'source_effective_at', 'source_volume_entry_id', 'remaining_millionths']);

            foreach ($lots as $lot) {
                $id = (string) $lot->id;
                $plan->storedLot($id, $member, $side, (string) $lot->source_effective_at, (string) $lot->source_volume_entry_id, (string) $lot->remaining_millionths);
                $held = (string) $plan->remainingOf($id);

                if ($held === '0') {
                    continue;
                }

                $take = PairingArithmetic::min($held, $needed);
                $plan->consumeStored($id, $member, $side, $take);
                $needed = PairingArithmetic::subtract($needed, $take);

                if ($needed === '0') {
                    return;
                }
            }

            if ($lots->count() < self::CHUNK) {
                break;
            }

            $last = $lots->last();
        }

        $needed = $plan->consumeNew($member, $side, $needed);

        if ($needed !== '0') {
            throw InvalidBinaryPairingState::changed($plan->component, "member [{$member}]'s {$side->value} carry holds less than it adds up to");
        }
    }

    /**
     * The members who earn, as stored, in the run's program.
     *
     * @param  list<string>  $ids
     * @return array<string, Member>
     */
    private function members(Connection $db, string $program, array $ids): array
    {
        $members = [];

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            foreach (Member::on($db->getName())->whereKey($chunk)->where('program_id', $program)->get() as $member) {
                $members[(string) $member->getKey()] = $member;
            }
        }

        foreach ($ids as $id) {
            if (! isset($members[$id])) {
                throw InvalidBinaryPairingState::corrupt($program, "binary member [{$id}] is not in program [{$program}]");
            }
        }

        return $members;
    }

    /**
     * Carry is only ever held by members of the run's program.
     *
     * @param  list<string>  $ids
     */
    private function assertInProgram(Connection $db, BinaryPairingPlan $plan, array $ids): void
    {
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $outside = $db->table(self::MEMBERS)->whereIn('id', $chunk)->where('program_id', '!=', $plan->program)->value('id');

            if (is_string($outside)) {
                throw InvalidBinaryPairingState::corrupt($plan->component, "binary member [{$outside}] of another program would hold carry of program [{$plan->program}]");
            }
        }
    }

    private function moment(mixed $stored): CarbonImmutable
    {
        return EffectiveMoment::of(CarbonImmutable::parse((string) $stored));
    }
}
