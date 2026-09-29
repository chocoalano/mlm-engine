<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Finance;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use PandaBear\Mlm\Support\EffectiveMoment;

/**
 * @internal
 *
 * The input rules the ledger shares. Refuses rather than rewrites: nothing
 * is lowercased, trimmed or rounded on the caller's behalf.
 */
final class FinanceInput
{
    /**
     * A transaction type or source type: 1–64 characters.
     */
    public const IDENTIFIER_LENGTH = 64;

    /**
     * A system account key: 1–100 characters.
     */
    public const ACCOUNT_KEY_LENGTH = 100;

    public const SOURCE_ID_LENGTH = 128;

    public const IDEMPOTENCY_KEY_LENGTH = 191;

    /**
     * Lowercase letters, digits, ".", "-" and "_", starting with a letter or
     * digit, at most `$length` characters. Never a PHP class name.
     */
    public static function isIdentifier(string $value, int $length): bool
    {
        return strlen($value) <= $length && preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $value) === 1;
    }

    /**
     * Text another system supplies — a source id, an idempotency key:
     * non-empty, no surrounding whitespace, no control characters, at most
     * `$length` characters.
     */
    public static function isText(string $value, int $length): bool
    {
        return $value !== ''
            && trim($value) === $value
            && mb_strlen($value) <= $length
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    /**
     * The same instant in the application's timezone, to the second, as
     * every business moment of the package is stored.
     */
    public static function moment(DateTimeInterface $at): CarbonImmutable
    {
        return EffectiveMoment::of($at);
    }
}
