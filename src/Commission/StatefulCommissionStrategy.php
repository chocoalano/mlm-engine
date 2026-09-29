<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission;

/**
 * A strategy whose result depends on state it keeps between runs — such as
 * binary carry (ADR-023) — and moves forward with each run.
 *
 * `calculateStateful()` reads, like `calculate()`: it returns the candidates
 * and, as data, the state transition that belongs to them. The calculation
 * engine stores the run and its commissions and applies that transition in
 * one serializable transaction, or none of it; a replayed run applies
 * nothing. The engine may run the calculation more than once when the
 * database asks for a retry, so it must stay read-only and deterministic:
 * no writes, network calls, events, clock or randomness.
 *
 * `calculate()` stays a read-only preview: its candidates move no state. An
 * authoritative result goes through `CalculationEngine`.
 */
interface StatefulCommissionStrategy extends CommissionStrategy
{
    public function calculateStateful(CommissionCalculationContext $context): StatefulCommissionCalculation;
}
