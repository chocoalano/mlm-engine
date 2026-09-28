<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Volume;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use PandaBear\Mlm\Exceptions\InvalidVolumeEntry;

/**
 * @internal
 *
 * The input rules shared by the volume commands and queries. Refuses rather
 * than rewrites: nothing is lowercased, trimmed or rounded on the caller's
 * behalf.
 */
final class VolumeInput
{
    /**
     * 1–64 characters: lowercase letters, digits, '.', '-' and '_',
     * starting with a letter or digit.
     */
    private const IDENTIFIER = '/^[a-z0-9][a-z0-9._-]{0,63}$/';

    /**
     * A machine identifier chosen by the application, such as a volume type
     * or a source type. Never a PHP class name.
     */
    public static function identifier(string $field, string $value): string
    {
        if (preg_match(self::IDENTIFIER, $value) !== 1) {
            throw InvalidVolumeEntry::identifier($field, $value);
        }

        return $value;
    }

    /**
     * Free text supplied by another system — a source id, an idempotency
     * key: non-empty, no surrounding whitespace, no control characters. An
     * integer becomes its string form.
     */
    public static function text(string $field, string|int $value, int $maxLength): string
    {
        $value = (string) $value;

        if ($value === ''
            || trim($value) !== $value
            || mb_strlen($value) > $maxLength
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw InvalidVolumeEntry::text($field, $value, $maxLength);
        }

        return $value;
    }

    /**
     * The same instant in the application's timezone, to the second — how
     * `effective_at` is stored and compared, so one moment given in two
     * timezones is one moment.
     */
    public static function moment(DateTimeInterface $at): CarbonImmutable
    {
        return CarbonImmutable::instance($at)
            ->setTimezone(date_default_timezone_get())
            ->startOfSecond();
    }
}
