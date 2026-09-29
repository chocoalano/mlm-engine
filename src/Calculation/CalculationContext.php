<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Calculation;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use PandaBear\Mlm\Exceptions\InvalidCalculationRun;
use PandaBear\Mlm\Finance\FinanceInput;

/**
 * What to calculate over, and the caller's identity for the request: a
 * closed range [from, until) — `from` included, `until` excluded, both
 * required, `from` first — normalised to the application's timezone and
 * whole seconds, and an idempotency key a replay repeats.
 */
final readonly class CalculationContext
{
    public CarbonImmutable $from;

    public CarbonImmutable $until;

    public string $idempotencyKey;

    /**
     * @param  string  $idempotencyKey  e.g. "weekly-2026-40-primary"
     */
    public function __construct(?DateTimeInterface $from, ?DateTimeInterface $until, string $idempotencyKey)
    {
        if ($from === null || $until === null) {
            throw InvalidCalculationRun::openRange();
        }

        $this->from = FinanceInput::moment($from);
        $this->until = FinanceInput::moment($until);

        if ($this->from->greaterThanOrEqualTo($this->until)) {
            throw InvalidCalculationRun::emptyRange($this->from->toDateTimeString(), $this->until->toDateTimeString());
        }

        if (! FinanceInput::isText($idempotencyKey, FinanceInput::IDEMPOTENCY_KEY_LENGTH)) {
            throw InvalidCalculationRun::idempotencyKey($idempotencyKey);
        }

        $this->idempotencyKey = $idempotencyKey;
    }
}
