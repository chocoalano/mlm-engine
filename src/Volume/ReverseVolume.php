<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Volume;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use PandaBear\Mlm\Models\VolumeEntry;

/**
 * A request to reverse one recorded entry. The reversal has its own source,
 * key and effective moment — a refund, a cancellation, a correction — rather
 * than borrowing the original's.
 */
final readonly class ReverseVolume
{
    public string $sourceType;

    public string $sourceId;

    public string $idempotencyKey;

    public CarbonImmutable $effectiveAt;

    public function __construct(
        public VolumeEntry $entry,
        string $sourceType,
        string|int $sourceId,
        string $idempotencyKey,
        DateTimeInterface $effectiveAt,
    ) {
        $this->sourceType = VolumeInput::identifier('source type', $sourceType);
        $this->sourceId = VolumeInput::text('source id', $sourceId, 128);
        $this->idempotencyKey = VolumeInput::text('idempotency key', $idempotencyKey, 191);
        $this->effectiveAt = VolumeInput::moment($effectiveAt);
    }
}
