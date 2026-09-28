<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Exceptions;

use DomainException;
use PandaBear\Mlm\Models\VolumeEntry;
use PandaBear\Mlm\Volume\Quantity;

final class InvalidVolumeEntry extends DomainException
{
    public static function quantity(mixed $value, string $reason): self
    {
        $given = is_string($value) ? "\"{$value}\"" : get_debug_type($value).(is_scalar($value) ? ' '.var_export($value, true) : '');

        return new self("{$given} is not a volume quantity: {$reason}.");
    }

    public static function notPositive(Quantity $quantity): self
    {
        return new self("A recorded volume quantity must be positive; {$quantity} given. A correction is a reversal, not a negative entry.");
    }

    public static function tooLargeForEntry(Quantity $quantity): self
    {
        return new self(sprintf(
            'A volume entry stores at most %d integer digits; %s is too large for one entry.',
            VolumeEntry::MAX_INTEGER_DIGITS,
            $quantity,
        ));
    }

    public static function identifier(string $field, string $value): self
    {
        return new self(sprintf(
            'The volume %s must be 1–64 lowercase letters, digits, ".", "-" or "_", starting with a letter or digit; "%s" given.',
            $field,
            $value,
        ));
    }

    public static function text(string $field, string $value, int $maxLength): self
    {
        return new self(sprintf(
            'The volume %s must be 1–%d characters with no surrounding whitespace or control characters; "%s" given.',
            $field,
            $maxLength,
            $value,
        ));
    }

    public static function outsideRecorder(): self
    {
        return new self('Volume entries are immutable and written only by PandaBear\Mlm\Volume\VolumeRecorder; a correction is a reversal.');
    }
}
