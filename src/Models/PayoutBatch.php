<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PandaBear\Mlm\Exceptions\ImmutableCalculationRecord;
use PandaBear\Mlm\Payout\PayoutBatchManager;
use PandaBear\Mlm\Payout\PayoutBatchStatus;

/**
 * A grouping of one program's payout requests in one currency, processed
 * together (ADR-030). It owns no money. Written by `PayoutBatchManager`
 * alone; read-only through Eloquent.
 *
 * @property string $id
 * @property string $program_id
 * @property string $currency
 * @property string $idempotency_key
 * @property PayoutBatchStatus $status
 * @property CarbonImmutable|null $sealed_at
 * @property CarbonImmutable|null $processing_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property-read Program $program
 * @property-read Collection<int, PayoutBatchItem> $items
 */
final class PayoutBatch extends MlmModel
{
    protected $table = 'mlm_payout_batches';

    /**
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PayoutBatchStatus::class,
            'sealed_at' => 'immutable_datetime',
            'processing_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Program, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /**
     * Its requests, in the order they were added.
     *
     * @return HasMany<PayoutBatchItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PayoutBatchItem::class)->orderBy('position');
    }

    protected static function booted(): void
    {
        $refuse = static function (): never {
            throw ImmutableCalculationRecord::outsideWriter(self::class, PayoutBatchManager::class);
        };

        self::creating($refuse);
        self::updating($refuse);
        self::deleting($refuse);
    }
}
