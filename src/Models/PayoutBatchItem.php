<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PandaBear\Mlm\Exceptions\ImmutableCalculationRecord;
use PandaBear\Mlm\Payout\PayoutBatchManager;

/**
 * A payout request's place in a batch (ADR-030). Written once by
 * `PayoutBatchManager`; never moved or removed. Read-only through Eloquent.
 *
 * @property string $id
 * @property string $payout_batch_id
 * @property string $payout_request_id
 * @property int $position
 * @property-read PayoutBatch $batch
 * @property-read PayoutRequest $request
 */
final class PayoutBatchItem extends MlmModel
{
    protected $table = 'mlm_payout_batch_items';

    /**
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /**
     * @return BelongsTo<PayoutBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(PayoutBatch::class, 'payout_batch_id');
    }

    /**
     * @return BelongsTo<PayoutRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(PayoutRequest::class, 'payout_request_id');
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
