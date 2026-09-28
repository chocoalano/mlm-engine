<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Volume;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use PandaBear\Mlm\Exceptions\InvalidVolumeEntry;
use PandaBear\Mlm\Models\Member;

/**
 * A request to record a quantity of one volume type for a member, from a
 * business source, effective at a moment. Validated on construction, so an
 * invalid request cannot exist.
 */
final readonly class RecordVolume
{
    public string $type;

    public string $sourceType;

    public string $sourceId;

    public string $idempotencyKey;

    public CarbonImmutable $effectiveAt;

    /**
     * @param  string  $type  the application's own volume type, e.g. "sales"
     * @param  string  $sourceType  what kind of business source, e.g. "order"
     * @param  string|int  $sourceId  which one, e.g. "ORD-123"
     * @param  string  $idempotencyKey  the caller's identity for this request; a replay repeats it
     */
    public function __construct(
        public Member $member,
        string $type,
        public Quantity $quantity,
        string $sourceType,
        string|int $sourceId,
        string $idempotencyKey,
        DateTimeInterface $effectiveAt,
    ) {
        if (! $quantity->isPositive()) {
            throw InvalidVolumeEntry::notPositive($quantity);
        }

        VolumeInput::storable($quantity);

        $this->type = VolumeInput::identifier('type', $type);
        $this->sourceType = VolumeInput::identifier('source type', $sourceType);
        $this->sourceId = VolumeInput::text('source id', $sourceId, 128);
        $this->idempotencyKey = VolumeInput::text('idempotency key', $idempotencyKey, 191);
        $this->effectiveAt = VolumeInput::moment($effectiveAt);
    }
}
