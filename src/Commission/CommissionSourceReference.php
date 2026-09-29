<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission;

use PandaBear\Mlm\Exceptions\InvalidCommissionSource;
use PandaBear\Mlm\Finance\FinanceInput;
use PandaBear\Mlm\Models\VolumeEntry;

/**
 * The business record a commission was earned from — a type and an id —
 * kept on the commission as relational provenance, beside its trace
 * (ADR-021). Optional: a strategy that names none keeps working as before.
 *
 * The type is a business key, never a class name. `volume-entry` names an
 * original volume entry by its id, and opts the commission into clawback
 * when that entry is later reversed: a strategy should use it only for a
 * commission earned wholly from that one entry.
 */
final readonly class CommissionSourceReference
{
    public const VOLUME_ENTRY = 'volume-entry';

    public const TYPE_LENGTH = 64;

    public const ID_LENGTH = 191;

    private function __construct(
        public string $type,
        public string $id,
    ) {}

    /**
     * @throws InvalidCommissionSource
     */
    public static function of(string $type, string|int $id): self
    {
        $id = (string) $id;

        if (! FinanceInput::isIdentifier($type, self::TYPE_LENGTH)) {
            throw InvalidCommissionSource::type($type);
        }

        if (! FinanceInput::isText($id, self::ID_LENGTH)) {
            throw InvalidCommissionSource::id($id);
        }

        return new self($type, $id);
    }

    /**
     * The original volume entry a commission was earned from.
     */
    public static function volumeEntry(VolumeEntry $entry): self
    {
        return self::of(self::VOLUME_ENTRY, (string) $entry->getKey());
    }
}
