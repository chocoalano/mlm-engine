<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests\Concerns;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\VolumeEntry;
use PandaBear\Mlm\Volume\Quantity;
use PandaBear\Mlm\Volume\RecordVolume;
use PandaBear\Mlm\Volume\ReverseVolume;
use PandaBear\Mlm\Volume\VolumeRecorder;
use PandaBear\Mlm\Volume\VolumeTotals;

/**
 * Volume written the supported way — through VolumeRecorder — with defaults
 * a test only overrides when it is about that field.
 */
trait RecordsVolume
{
    protected function recorder(): VolumeRecorder
    {
        return $this->app->make(VolumeRecorder::class);
    }

    protected function totals(): VolumeTotals
    {
        return $this->app->make(VolumeTotals::class);
    }

    protected function record(
        Member $member,
        string $quantity,
        string $key,
        string $type = 'sales',
        string $sourceType = 'order',
        string|int $sourceId = 'ORD-1',
        ?DateTimeInterface $at = null,
    ): VolumeEntry {
        return $this->recorder()->record(new RecordVolume(
            member: $member,
            type: $type,
            quantity: Quantity::of($quantity),
            sourceType: $sourceType,
            sourceId: $sourceId,
            idempotencyKey: $key,
            effectiveAt: $at ?? CarbonImmutable::parse('2026-06-01 12:00:00'),
        ));
    }

    protected function reverse(
        VolumeEntry $entry,
        string $key,
        string $sourceType = 'refund',
        string|int $sourceId = 'RF-1',
        ?DateTimeInterface $at = null,
    ): VolumeEntry {
        return $this->recorder()->reverse(new ReverseVolume(
            entry: $entry,
            sourceType: $sourceType,
            sourceId: $sourceId,
            idempotencyKey: $key,
            effectiveAt: $at ?? CarbonImmutable::parse('2026-06-15 12:00:00'),
        ));
    }

    /**
     * Every stored volume row, exactly as stored, keyed by id.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function volumeRows(?string $connection = null): array
    {
        return DB::connection($connection)->table('mlm_volume_entries')->orderBy('id')->get()
            ->mapWithKeys(static fn (object $row): array => [$row->id => (array) $row])
            ->all();
    }
}
