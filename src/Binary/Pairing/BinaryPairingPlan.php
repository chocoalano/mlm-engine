<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Binary\Pairing;

use Carbon\CarbonImmutable;
use PandaBear\Mlm\Binary\BinarySide;
use PandaBear\Mlm\Exceptions\InvalidBinaryPairingState;

/**
 * @internal
 *
 * Everything one binary pairing run decided, as data (ADR-023, ADR-025):
 * the cursor it read, the lots it takes in and back, the earlier pairs it
 * undoes and the carry that gives back, what each member's pairs consume lot
 * by lot, and each member's result. Built while calculating, applied by
 * `BinaryPairingStateTransition`; quantities are whole millionths as exact
 * decimal digits.
 */
final class BinaryPairingPlan
{
    /**
     * New lots, by member and entry: `member|entry`.
     *
     * @var array<string, array{member: string, side: BinarySide, entry: string, effective_at: string, quantity: string, remaining: string, reversed_by: ?string}>
     */
    private array $newLots = [];

    /**
     * The new lots' keys, by member and side, in the order they were added.
     *
     * @var array<string, array<string, list<string>>>
     */
    private array $newLotsBySide = [];

    /**
     * Stored lots this run changes, by id: what it read of them, and what it
     * leaves — taken back by a reversal, given back by a correction, drawn
     * on by its pairs.
     *
     * @var array<string, array{member: string, side: BinarySide, effective_at: string, entry: string, expected: string, remaining: string, reversed_by: ?string}>
     */
    private array $storedLots = [];

    /**
     * Earlier pairs this run undoes, in the order it undoes them.
     *
     * @var list<array{reversal: string, original: string, result: string, allocation: string, member: string, side: BinarySide, quantity: string, commission: ?string, restorations: list<array{allocation: string, lot: string, side: BinarySide, quantity: string}>}>
     */
    private array $corrections = [];

    /**
     * What earlier corrections had released of each allocation this run's
     * corrections read, as read: invalidated and restored millionths.
     *
     * @var array<string, array{string, string}>
     */
    private array $releases = [];

    /**
     * By member: carry before, added, given back by corrections and taken
     * back by reversals, by side.
     *
     * @var array<string, array{before: array<string, string>, added: array<string, string>, restored: array<string, string>, reversed: array<string, string>}>
     */
    private array $members = [];

    /**
     * By member, once paired: pair quantity, pair count, consumed per side,
     * and the candidate key of the commission it earned.
     *
     * @var array<string, array{pair_quantity: string, pair_count: string, consumed: string, candidate: ?string}>
     */
    private array $settled = [];

    /**
     * By member: every lot its pairs draw on, each side oldest first.
     *
     * @var array<string, list<array{lot: string, stored: bool, side: BinarySide, quantity: string}>>
     */
    private array $allocations = [];

    public function __construct(
        public readonly string $component,
        public readonly string $program,
        public readonly ?object $cursor,
        public readonly CarbonImmutable $startedAt,
        public readonly CarbonImmutable $until,
    ) {}

    /**
     * @param  string  $effectiveAt  the entry's moment as stored, `Y-m-d H:i:s`: it sorts as it compares
     */
    public function addLot(string $member, BinarySide $side, string $entry, string $effectiveAt, string $quantity, ?string $reversal): void
    {
        $key = "{$member}|{$entry}";

        // One entry falls in one leg of a member at most: a tree has one
        // path from a member down to another.
        if (isset($this->newLots[$key])) {
            throw InvalidBinaryPairingState::corrupt($this->component, "volume entry [{$entry}] falls in both legs of member [{$member}]");
        }

        $this->newLots[$key] = [
            'member' => $member,
            'side' => $side,
            'entry' => $entry,
            'effective_at' => $effectiveAt,
            'quantity' => $quantity,
            'remaining' => $reversal === null ? $quantity : '0',
            'reversed_by' => $reversal,
        ];

        $this->newLotsBySide[$member][$side->value][] = $key;
        $this->add($member, 'added', $side, $quantity);

        if ($reversal !== null) {
            $this->add($member, 'reversed', $side, $quantity);
        }
    }

    /**
     * Registers a stored lot the run changes, as it read it — once.
     */
    public function storedLot(string $lot, string $member, BinarySide $side, string $effectiveAt, string $entry, string $held): void
    {
        $this->storedLots[$lot] ??= [
            'member' => $member,
            'side' => $side,
            'effective_at' => substr($effectiveAt, 0, 19),
            'entry' => $entry,
            'expected' => $held,
            'remaining' => $held,
            'reversed_by' => null,
        ];
    }

    /**
     * What a registered stored lot holds as planned so far; null for one the
     * run has not touched.
     */
    public function remainingOf(string $lot): ?string
    {
        return $this->storedLots[$lot]['remaining'] ?? null;
    }

    /**
     * Takes back what a registered stored lot still holds: its source was
     * reversed.
     */
    public function reverseStored(string $lot, string $reversal): void
    {
        $stored = $this->storedLots[$lot];
        $this->add($stored['member'], 'reversed', $stored['side'], $stored['remaining']);
        $this->storedLots[$lot]['remaining'] = '0';
        $this->storedLots[$lot]['reversed_by'] = $reversal;
    }

    /**
     * Gives quantity back to a registered stored lot: an earlier pair it was
     * drawn into is undone.
     */
    public function restoreStored(string $lot, string $quantity): void
    {
        $stored = $this->storedLots[$lot];
        $this->add($stored['member'], 'restored', $stored['side'], $quantity);
        $this->storedLots[$lot]['remaining'] = PairingArithmetic::add($stored['remaining'], $quantity);
    }

    public function isReversed(string $lot): bool
    {
        return ($this->storedLots[$lot]['reversed_by'] ?? null) !== null;
    }

    /**
     * Stored lots of the member's side that held nothing when read and hold
     * carry again because a correction gave it back — lots a read of open
     * carry does not find.
     *
     * @return array<string, array{effective_at: string, entry: string}> by id
     */
    public function revived(string $member, BinarySide $side): array
    {
        $revived = [];

        foreach ($this->storedLots as $id => $lot) {
            if ($lot['member'] === $member && $lot['side'] === $side && $lot['expected'] === '0' && $lot['remaining'] !== '0' && $lot['reversed_by'] === null) {
                $revived[$id] = ['effective_at' => $lot['effective_at'], 'entry' => $lot['entry']];
            }
        }

        return $revived;
    }

    /**
     * @param  array{reversal: string, original: string, result: string, allocation: string, member: string, side: BinarySide, quantity: string, commission: ?string, restorations: list<array{allocation: string, lot: string, side: BinarySide, quantity: string}>}  $correction
     */
    public function correct(array $correction): void
    {
        $this->corrections[] = $correction;
    }

    /**
     * Records what earlier corrections had released of an allocation, as
     * read — once, before this run releases more.
     */
    public function expectRelease(string $allocation, string $invalidated, string $restored): void
    {
        $this->releases[$allocation] ??= [$invalidated, $restored];
    }

    public function carry(string $member, BinarySide $side, string $quantity): void
    {
        $this->add($member, 'before', $side, $quantity);
    }

    /**
     * Whether the member had stored carry on the side before this run.
     */
    public function carries(string $member, BinarySide $side): bool
    {
        return $this->of($member, 'before', $side) !== '0';
    }

    /**
     * Every member with carry before, or anything added or taken back, by id.
     *
     * @return list<string>
     */
    public function members(): array
    {
        $members = array_map('strval', array_keys($this->members));
        sort($members, SORT_STRING);

        return $members;
    }

    /**
     * @return list<string>
     */
    public function anchors(): array
    {
        return array_values(array_unique(array_map(static fn (array $lot): string => $lot['member'], $this->newLots)));
    }

    public function available(string $member, BinarySide $side): string
    {
        return PairingArithmetic::subtract(
            PairingArithmetic::add(PairingArithmetic::add($this->of($member, 'before', $side), $this->of($member, 'added', $side)), $this->of($member, 'restored', $side)),
            $this->of($member, 'reversed', $side),
        );
    }

    /**
     * Draws on a registered stored lot.
     */
    public function consumeStored(string $lot, string $member, BinarySide $side, string $quantity): void
    {
        $this->storedLots[$lot]['remaining'] = PairingArithmetic::subtract($this->storedLots[$lot]['remaining'], $quantity);
        $this->allocations[$member][] = ['lot' => $lot, 'stored' => true, 'side' => $side, 'quantity' => $quantity];
    }

    /**
     * Draws on this run's own lots of the member's side, oldest first, and
     * returns what is still needed.
     */
    public function consumeNew(string $member, BinarySide $side, string $needed): string
    {
        $lots = [];

        foreach ($this->newLotsBySide[$member][$side->value] ?? [] as $key) {
            if ($this->newLots[$key]['remaining'] !== '0') {
                $lots[$key] = $this->newLots[$key];
            }
        }

        uasort($lots, static fn (array $a, array $b): int => strcmp($a['effective_at'], $b['effective_at']) ?: strcmp($a['entry'], $b['entry']));

        foreach ($lots as $key => $lot) {
            if ($needed === '0') {
                break;
            }

            $take = PairingArithmetic::min($lot['remaining'], $needed);
            $this->newLots[$key]['remaining'] = PairingArithmetic::subtract($lot['remaining'], $take);
            $this->allocations[$member][] = ['lot' => $key, 'stored' => false, 'side' => $side, 'quantity' => $take];
            $needed = PairingArithmetic::subtract($needed, $take);
        }

        return $needed;
    }

    public function settle(string $member, string $pairQuantity, string $pairCount, string $consumed): void
    {
        $this->settled[$member] = ['pair_quantity' => $pairQuantity, 'pair_count' => $pairCount, 'consumed' => $consumed, 'candidate' => null];
    }

    public function earns(string $member, string $candidateKey): void
    {
        $this->settled[$member]['candidate'] = $candidateKey;
    }

    public function pairCount(string $member): string
    {
        return $this->settled[$member]['pair_count'];
    }

    public function consumed(string $member): string
    {
        return $this->settled[$member]['consumed'];
    }

    /**
     * The member's carry, before and after, and what came and went — as
     * quantities.
     *
     * @return array<string, string>
     */
    public function carryTrace(string $member): array
    {
        $trace = [];

        foreach (BinarySide::cases() as $side) {
            $trace["{$side->value}_before"] = PairingArithmetic::quantity($this->of($member, 'before', $side));
            $trace["{$side->value}_added"] = PairingArithmetic::quantity($this->of($member, 'added', $side));
            $trace["{$side->value}_restored"] = PairingArithmetic::quantity($this->of($member, 'restored', $side));
            $trace["{$side->value}_reversed"] = PairingArithmetic::quantity($this->of($member, 'reversed', $side));
            $trace["{$side->value}_after"] = PairingArithmetic::quantity(PairingArithmetic::subtract($this->available($member, $side), $this->consumed($member)));
        }

        return $trace;
    }

    /**
     * @return array<string, array{member: string, side: BinarySide, entry: string, effective_at: string, quantity: string, remaining: string, reversed_by: ?string}>
     */
    public function newLots(): array
    {
        return $this->newLots;
    }

    /**
     * @return array<string, array{member: string, side: BinarySide, effective_at: string, entry: string, expected: string, remaining: string, reversed_by: ?string}>
     */
    public function storedLots(): array
    {
        return $this->storedLots;
    }

    /**
     * @return list<array{reversal: string, original: string, result: string, allocation: string, member: string, side: BinarySide, quantity: string, commission: ?string, restorations: list<array{allocation: string, lot: string, side: BinarySide, quantity: string}>}>
     */
    public function corrections(): array
    {
        return $this->corrections;
    }

    /**
     * @return array<string, array{string, string}>
     */
    public function releases(): array
    {
        return $this->releases;
    }

    /**
     * Each member's result, as the columns store it, in member order.
     *
     * @return list<array<string, string|null>>
     */
    public function results(): array
    {
        $results = [];

        foreach ($this->members() as $member) {
            $settled = $this->settled[$member];
            $row = ['member_id' => $member];

            foreach (BinarySide::cases() as $side) {
                $row["{$side->value}_carry_before"] = PairingArithmetic::quantity($this->of($member, 'before', $side));
                $row["{$side->value}_added"] = PairingArithmetic::quantity($this->of($member, 'added', $side));
                $row["{$side->value}_restored"] = PairingArithmetic::quantity($this->of($member, 'restored', $side));
                $row["{$side->value}_reversed"] = PairingArithmetic::quantity($this->of($member, 'reversed', $side));
                $row["{$side->value}_available"] = PairingArithmetic::quantity($this->available($member, $side));
                $row["{$side->value}_carry_after"] = PairingArithmetic::quantity(PairingArithmetic::subtract($this->available($member, $side), $settled['consumed']));
            }

            $results[] = [
                ...$row,
                'pair_quantity' => $settled['pair_quantity'],
                'pair_count' => $settled['pair_count'],
                'consumed_quantity' => PairingArithmetic::quantity($settled['consumed']),
                'candidate' => $settled['candidate'],
            ];
        }

        return $results;
    }

    /**
     * @return list<array{lot: string, stored: bool, side: BinarySide, quantity: string}>
     */
    public function allocations(string $member): array
    {
        return $this->allocations[$member] ?? [];
    }

    private function add(string $member, string $field, BinarySide $side, string $quantity): void
    {
        $this->members[$member] ??= ['before' => [], 'added' => [], 'restored' => [], 'reversed' => []];
        $this->members[$member][$field][$side->value] = PairingArithmetic::add($this->members[$member][$field][$side->value] ?? '0', $quantity);
    }

    private function of(string $member, string $field, BinarySide $side): string
    {
        return $this->members[$member][$field][$side->value] ?? '0';
    }
}
