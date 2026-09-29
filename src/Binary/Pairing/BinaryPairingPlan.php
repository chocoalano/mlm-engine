<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Binary\Pairing;

use Carbon\CarbonImmutable;
use PandaBear\Mlm\Binary\BinarySide;
use PandaBear\Mlm\Exceptions\InvalidBinaryPairingState;

/**
 * @internal
 *
 * Everything one binary pairing run decided, as data (ADR-023): the cursor
 * it read, the lots it takes in and back, what each member's pairs consume
 * lot by lot, and each member's result. Built while calculating, applied by
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
     * Stored lots taken back whole by a reversal in this run, by id.
     *
     * @var array<string, array{quantity: string, reversal: string}>
     */
    private array $reversedLots = [];

    /**
     * Stored lots this run's pairs draw on, by id: what they held, and what
     * they keep.
     *
     * @var array<string, array{expected: string, remaining: string}>
     */
    private array $consumedLots = [];

    /**
     * By member: carry before, added and taken back, by side.
     *
     * @var array<string, array{before: array<string, string>, added: array<string, string>, reversed: array<string, string>}>
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

    public function reverseLot(string $lot, string $member, BinarySide $side, string $quantity, string $reversal): void
    {
        $this->reversedLots[$lot] = ['quantity' => $quantity, 'reversal' => $reversal];
        $this->add($member, 'reversed', $side, $quantity);
    }

    public function isReversed(string $lot): bool
    {
        return isset($this->reversedLots[$lot]);
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
            PairingArithmetic::add($this->of($member, 'before', $side), $this->of($member, 'added', $side)),
            $this->of($member, 'reversed', $side),
        );
    }

    public function consumeStored(string $lot, string $member, BinarySide $side, string $held, string $quantity): void
    {
        $this->consumedLots[$lot] = ['expected' => $held, 'remaining' => PairingArithmetic::subtract($held, $quantity)];
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
     * @return array<string, array{quantity: string, reversal: string}>
     */
    public function reversedLots(): array
    {
        return $this->reversedLots;
    }

    /**
     * @return array<string, array{expected: string, remaining: string}>
     */
    public function consumedLots(): array
    {
        return $this->consumedLots;
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
        $this->members[$member] ??= ['before' => [], 'added' => [], 'reversed' => []];
        $this->members[$member][$field][$side->value] = PairingArithmetic::add($this->members[$member][$field][$side->value] ?? '0', $quantity);
    }

    private function of(string $member, string $field, BinarySide $side): string
    {
        return $this->members[$member][$field][$side->value] ?? '0';
    }
}
