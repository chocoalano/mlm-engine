<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Finance;

use PandaBear\Mlm\Exceptions\InvalidLedgerTransaction;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\LedgerPosting;

/**
 * One line of a transaction to post: an account and a signed amount —
 * positive raises its balance, negative lowers it. Never zero, and never
 * larger either way than one posting can store and reverse.
 */
final readonly class LedgerPostingInput
{
    public function __construct(
        public LedgerAccount $account,
        public FinancialAmount $amount,
    ) {
        if ($amount->isZero()) {
            throw InvalidLedgerTransaction::zeroPosting();
        }

        $limit = FinancialAmount::fromMillionths(LedgerPosting::MAX_MILLIONTHS);

        if ($amount->compare($limit) > 0 || $amount->compare($limit->negate()) < 0) {
            throw InvalidLedgerTransaction::tooLargeForPosting($amount, $limit->value());
        }
    }

    /**
     * @param  FinancialAmount|string|int  $amount  e.g. "-100" or "25.5"
     */
    public static function of(LedgerAccount $account, FinancialAmount|string|int $amount): self
    {
        return new self($account, $amount instanceof FinancialAmount ? $amount : FinancialAmount::of($amount));
    }
}
