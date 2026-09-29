<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Finance;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use PandaBear\Mlm\Exceptions\InvalidLedgerTransaction;
use PandaBear\Mlm\Models\LedgerTransaction;

/**
 * A request to reverse a posted transaction: a new transaction with every
 * posting negated, from its own business source, under its own idempotency
 * key and moment. The amounts are never the caller's: they come from the
 * original.
 */
final readonly class ReverseLedgerTransaction
{
    public string $sourceType;

    public string $sourceId;

    public string $idempotencyKey;

    public CarbonImmutable $occurredAt;

    public function __construct(
        public LedgerTransaction $transaction,
        string $sourceType,
        string|int $sourceId,
        string $idempotencyKey,
        DateTimeInterface $occurredAt,
    ) {
        if (! FinanceInput::isIdentifier($sourceType, FinanceInput::IDENTIFIER_LENGTH)) {
            throw InvalidLedgerTransaction::identifier('source type', $sourceType);
        }

        foreach (['source id' => [(string) $sourceId, FinanceInput::SOURCE_ID_LENGTH], 'idempotency key' => [$idempotencyKey, FinanceInput::IDEMPOTENCY_KEY_LENGTH]] as $field => [$value, $length]) {
            if (! FinanceInput::isText($value, $length)) {
                throw InvalidLedgerTransaction::text($field, $value, $length);
            }
        }

        $this->sourceType = $sourceType;
        $this->sourceId = (string) $sourceId;
        $this->idempotencyKey = $idempotencyKey;
        $this->occurredAt = FinanceInput::moment($occurredAt);
    }
}
