<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use PandaBear\Mlm\Exceptions\InvalidCommissionCandidate;
use PandaBear\Mlm\Finance\FinanceInput;
use PandaBear\Mlm\Finance\FinancialAmount;
use PandaBear\Mlm\Models\LedgerPosting;
use PandaBear\Mlm\Models\Member;

/**
 * One commission a strategy found earned: who earns it, how much, when, and
 * why. Its currency is the component's. Validated on construction, so an
 * invalid candidate cannot exist:
 *
 * - its key identifies it within its run — 1–191 characters of any text,
 *   such as the business source it was earned from;
 * - its amount is exact and strictly positive, and fits one ledger posting;
 * - it was earned at an explicit business moment, never "now";
 * - its trace is inert JSON data, kept in canonical order.
 */
final readonly class CommissionCandidate
{
    public string $key;

    public FinancialAmount $amount;

    public CarbonImmutable $earnedAt;

    /**
     * @var array<array-key, mixed>
     */
    public array $trace;

    /**
     * @param  FinancialAmount|string|int  $amount  e.g. "12.5"
     * @param  array<array-key, mixed>  $trace  why it was earned: source ids, rule keys, values as strings
     */
    public function __construct(
        string $key,
        public Member $member,
        FinancialAmount|string|int $amount,
        DateTimeInterface $earnedAt,
        array $trace = [],
    ) {
        if (! FinanceInput::isText($key, FinanceInput::IDEMPOTENCY_KEY_LENGTH)) {
            throw InvalidCommissionCandidate::key($key);
        }

        $amount = $amount instanceof FinancialAmount ? $amount : FinancialAmount::of($amount);

        if (! $amount->isPositive()) {
            throw InvalidCommissionCandidate::notPositive($key, $amount);
        }

        $limit = FinancialAmount::fromMillionths(LedgerPosting::MAX_MILLIONTHS);

        if ($amount->compare($limit) > 0) {
            throw InvalidCommissionCandidate::tooLarge($key, $amount, $limit->value());
        }

        $problem = CommissionTrace::problem($trace);

        if ($problem !== null) {
            throw InvalidCommissionCandidate::trace($key, $problem);
        }

        $this->key = $key;
        $this->amount = $amount;
        $this->earnedAt = FinanceInput::moment($earnedAt);
        $this->trace = CommissionTrace::canonical($trace);
    }
}
