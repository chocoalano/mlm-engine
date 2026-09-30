<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Payouts;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use PandaBear\Mlm\Exceptions\InvalidPayoutBatch;
use PandaBear\Mlm\Models\PayoutBatch;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Support\DomainAction;
use PandaBear\Mlm\Payout\PayoutBatchManager;
use PandaBear\Mlm\Payout\PayoutBatchStatus;
use PandaPanel\Actions\Action;

/**
 * A payout batch's lifecycle through `PayoutBatchManager`. A batch groups
 * requests and never moves money; adding requests to one belongs to
 * Phase 3.9B.
 */
final class PayoutBatchActions
{
    /**
     * @return list<Action>
     */
    public static function all(): array
    {
        return [
            self::step('seal', 'shield', PayoutBatchStatus::Open, static fn (PayoutBatch $batch, CarbonImmutable $at) => app(PayoutBatchManager::class)->seal($batch, $at)),
            self::step('start-batch', 'upload', PayoutBatchStatus::Sealed, static fn (PayoutBatch $batch, CarbonImmutable $at) => app(PayoutBatchManager::class)->startProcessing($batch, $at)),
            self::step('complete', 'check', PayoutBatchStatus::Processing, static fn (PayoutBatch $batch, CarbonImmutable $at) => app(PayoutBatchManager::class)->complete($batch, $at)),
            self::step('cancel-batch', 'x', PayoutBatchStatus::Open, static fn (PayoutBatch $batch, CarbonImmutable $at) => app(PayoutBatchManager::class)->cancel($batch, $at)),
        ];
    }

    /**
     * @param  Closure(PayoutBatch, CarbonImmutable): mixed  $step
     */
    private static function step(string $name, string $icon, PayoutBatchStatus $from, Closure $step): Action
    {
        return DomainAction::confirmed($name, MlmPermission::PAYOUTS_OPERATE)
            ->icon($icon)
            ->visible(static fn (?Model $batch = null): bool => $batch instanceof PayoutBatch && $batch->status === $from)
            ->action(static function (PayoutBatch $batch) use ($name, $step): void {
                DomainAction::attempt($name, [InvalidPayoutBatch::class], static fn () => $step($batch, CarbonImmutable::now()));
            });
    }
}
