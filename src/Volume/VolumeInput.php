<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Volume;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use PandaBear\Mlm\Exceptions\InvalidVolumeEntry;
use PandaBear\Mlm\Models\VolumeEntry;
use PandaBear\Mlm\Support\EffectiveMoment;

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
    private const IDENTIFIER = '/^[a-z0-9][a-z0-9._-]{0,63}$/D';

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
     * A quantity one volume entry can store: at most
     * `VolumeEntry::MAX_INTEGER_DIGITS` integer digits, so its count of
     * millionths fits the 64-bit column. Refused rather than truncated.
     */
    public static function storable(Quantity $quantity): Quantity
    {
        $digits = strlen(ltrim($quantity->toMillionths(), '-'));

        if ($digits > VolumeEntry::MAX_INTEGER_DIGITS + Quantity::SCALE) {
            throw InvalidVolumeEntry::tooLargeForEntry($quantity);
        }

        return $quantity;
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
     * An effective range `[from, until)` as normalised moments, either bound
     * open. With both bounds, `from` must come before `until`: an empty or
     * inverted range is a mistake, not a range with no volume in it.
     *
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    public static function range(?DateTimeInterface $from, ?DateTimeInterface $until): array
    {
        $from = $from === null ? null : self::moment($from);
        $until = $until === null ? null : self::moment($until);

        if ($from !== null && $until !== null && $from->greaterThanOrEqualTo($until)) {
            throw new InvalidArgumentException(sprintf(
                'An effective range must start before it ends; [%s, %s) is empty.',
                $from->toDateTimeString(),
                $until->toDateTimeString(),
            ));
        }

        return [$from, $until];
    }

    /**
     * The same instant in the application's timezone, to the second — how
     * `effective_at` is stored and compared, so one moment given in two
     * timezones is one moment.
     */
    public static function moment(DateTimeInterface $at): CarbonImmutable
    {
        return EffectiveMoment::of($at);
    }
}
