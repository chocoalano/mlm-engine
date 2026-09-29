<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Finance;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use PandaBear\Mlm\Exceptions\InvalidLedgerTransaction;
use PandaBear\Mlm\Models\Program;

/**
 * A request to post one balanced transaction in one program and one
 * currency: at least two postings, one per account, none zero, summing to
 * exactly zero. It names its business source and the caller's idempotency
 * key, and when it occurred. Validated on construction, so an invalid
 * request cannot exist; the recorder then checks every account against the
 * database.
 */
final readonly class PostLedgerTransaction
{
    public CurrencyCode $currency;

    public string $type;

    public string $sourceType;

    public string $sourceId;

    public string $idempotencyKey;

    public CarbonImmutable $occurredAt;

    /**
     * @var list<LedgerPostingInput>
     */
    public array $postings;

    /**
     * @param  string  $type  what kind of movement, e.g. "adjustment"
     * @param  string  $sourceType  what kind of business source, e.g. "manual"
     * @param  string|int  $sourceId  which one, e.g. "ADJ-1"
     * @param  string  $idempotencyKey  the caller's identity for this request; a replay repeats it
     * @param  list<LedgerPostingInput>  $postings  in any order: order carries no meaning
     */
    public function __construct(
        public Program $program,
        CurrencyCode|string $currency,
        string $type,
        string $sourceType,
        string|int $sourceId,
        string $idempotencyKey,
        DateTimeInterface $occurredAt,
        array $postings,
    ) {
        $this->currency = CurrencyCode::from($currency);
        $this->type = self::identifier('type', $type);
        $this->sourceType = self::identifier('source type', $sourceType);
        $this->sourceId = self::text('source id', (string) $sourceId, FinanceInput::SOURCE_ID_LENGTH);
        $this->idempotencyKey = self::text('idempotency key', $idempotencyKey, FinanceInput::IDEMPOTENCY_KEY_LENGTH);
        $this->occurredAt = FinanceInput::moment($occurredAt);
        $this->postings = self::postings($postings);
    }

    /**
     * @param  array<mixed>  $postings
     * @return list<LedgerPostingInput>
     */
    private static function postings(array $postings): array
    {
        $accounts = [];

        foreach ($postings as $posting) {
            if (! $posting instanceof LedgerPostingInput) {
                throw InvalidLedgerTransaction::notAPosting($posting);
            }

            $account = (string) $posting->account->getKey();

            if (isset($accounts[$account])) {
                throw InvalidLedgerTransaction::duplicateAccount($account);
            }

            $accounts[$account] = true;
        }

        if (count($postings) < 2) {
            throw InvalidLedgerTransaction::tooFewPostings(count($postings));
        }

        $sum = FinancialAmount::sum(array_map(static fn (LedgerPostingInput $posting): FinancialAmount => $posting->amount, $postings));

        if (! $sum->isZero()) {
            throw InvalidLedgerTransaction::unbalanced($sum);
        }

        return array_values($postings);
    }

    private static function identifier(string $field, string $value): string
    {
        if (! FinanceInput::isIdentifier($value, FinanceInput::IDENTIFIER_LENGTH)) {
            throw InvalidLedgerTransaction::identifier($field, $value);
        }

        return $value;
    }

    private static function text(string $field, string $value, int $length): string
    {
        if (! FinanceInput::isText($value, $length)) {
            throw InvalidLedgerTransaction::text($field, $value, $length);
        }

        return $value;
    }
}
