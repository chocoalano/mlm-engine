<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission;

use DateTimeInterface;
use LogicException;
use PandaBear\Mlm\Finance\FinancialAmount;
use PandaBear\Mlm\Finance\LedgerPostingInput;
use PandaBear\Mlm\Finance\LedgerRecorder;
use PandaBear\Mlm\Finance\PostLedgerTransaction;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\LedgerTransaction;
use PandaBear\Mlm\Models\Program;

/**
 * @internal
 *
 * Moves part of a posted commission's money back (ADR-026), through the
 * ledger and nothing else: one balanced transaction debiting the member's
 * wallet and crediting the run's source account — the commission's own
 * posting run backwards, for the correction's amount only. The original
 * posting is never touched, and the commission stays POSTED. The wallet may
 * go negative: the ledger has no balance floor.
 *
 * Identified by the commission and the record that required the
 * correction, never by the clock, so a replay returns the transaction
 * already posted and never moves money twice. Only
 * `CommissionAdjustmentEngine` calls it, inside its own transaction, with
 * the commission locked and the adjustment about to be recorded.
 */
final readonly class CommissionAdjustmentPoster
{
    public const TYPE = 'commission-adjustment';

    public const SOURCE_TYPE = 'commission-adjustment';

    public function __construct(
        private LedgerRecorder $ledger,
        private CommissionAccounts $accounts,
    ) {}

    /**
     * @param  FinancialAmount  $amount  how much moves back: positive
     * @param  string  $sourceType  the kind of record that required it, as its adjustment names it
     * @param  string  $sourceId  which one
     */
    public function post(Commission $commission, FinancialAmount $amount, string $sourceType, string $sourceId, DateTimeInterface $occurredAt): LedgerTransaction
    {
        if (! $amount->isPositive()) {
            throw new LogicException("A correction moving {$amount} is not posted: only a positive amount moves money back.");
        }

        $db = $commission->getConnection();

        return $db->transaction(function () use ($db, $commission, $amount, $sourceType, $sourceId, $occurredAt): LedgerTransaction {
            $current = CommissionStatusWriter::lock($db, $commission);

            if ($current->status !== CommissionStatus::Posted) {
                throw new LogicException("Commission [{$current->getKey()}] is {$current->status->value}; only a posted commission's money is moved back.");
            }

            [$source, $wallet] = $this->accounts->of($db, $current);

            return $this->ledger->post(new PostLedgerTransaction(
                program: Program::on($db->getName())->findOrFail($current->program_id),
                currency: $current->currency,
                type: self::TYPE,
                sourceType: self::SOURCE_TYPE,
                sourceId: $current->getKey().':'.$sourceId,
                idempotencyKey: self::key($current, $sourceType, $sourceId),
                occurredAt: $occurredAt,
                postings: [
                    LedgerPostingInput::of($wallet, $amount->negate()),
                    LedgerPostingInput::of($source, $amount),
                ],
            ));
        });
    }

    public static function key(Commission $commission, string $sourceType, string $sourceId): string
    {
        return "commission.adjust.{$commission->getKey()}.{$sourceType}.{$sourceId}";
    }
}
