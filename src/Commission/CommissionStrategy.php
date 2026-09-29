<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission;

use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;

/**
 * Trusted code that calculates one kind of commission, selected by key from
 * a `commission.strategy` component: the component stores the key, never a
 * class name, and the key finds this already-registered object in the
 * `CommissionStrategyRegistry`.
 *
 * A strategy calculates; it does not write. `calculate()` reads what it
 * needs — through the package's models and read services, such as metrics,
 * qualification and rank — and returns candidates. The calculation engine
 * validates and stores them. A strategy must not insert commissions, post to
 * the ledger, open wallets or change plan definitions; this is a contract
 * for trusted code, not a sandbox.
 */
interface CommissionStrategy
{
    /**
     * The stable key components store: 1–100 lowercase letters, digits, ".",
     * "-" or "_", starting with a letter or digit. Namespace your own:
     * "acme.referral".
     */
    public function key(): string;

    /**
     * Refuses a definition — its parameters, its rules — this strategy cannot
     * calculate with. Reads no business data and writes nothing: called when
     * a version is validated, and again before each calculation.
     *
     * @throws InvalidPlanDefinition
     */
    public function validate(CommissionStrategyDefinition $definition): void;

    /**
     * The commissions earned over the context's range, as candidates, in any
     * order. Every read inside it sees one database snapshot.
     *
     * @return iterable<CommissionCandidate>
     */
    public function calculate(CommissionCalculationContext $context): iterable;
}
