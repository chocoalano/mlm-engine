<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Payouts;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use PandaBear\Mlm\Exceptions\ConflictingPayoutBatch;
use PandaBear\Mlm\Exceptions\InvalidCurrencyCode;
use PandaBear\Mlm\Exceptions\InvalidPayoutBatch;
use PandaBear\Mlm\Models\PayoutBatch;
use PandaBear\Mlm\Models\PayoutRequest;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Support\Display;
use PandaBear\Mlm\Panel\Support\DomainAction;
use PandaBear\Mlm\Panel\Support\Options;
use PandaBear\Mlm\Payout\PayoutBatchManager;
use PandaBear\Mlm\Payout\PayoutBatchStatus;
use PandaPanel\Actions\Action;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Support\FormState;

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

    public static function creation(): Action
    {
        return DomainAction::withForm('new-payout-batch', MlmPermission::PAYOUTS_OPERATE)
            ->icon('plus')
            ->schema(static fn (): FormSchema => FormSchema::make()->schema([
                Select::make('program_id')
                    ->label(Display::field('program'))
                    ->searchable()
                    ->existsIn(...Options::exists(Program::class))
                    ->optionsUsing(static fn (FormState $state, ?string $search = null): array => Options::programs($search))
                    ->required(),
                TextInput::make('currency')->label(Display::field('currency'))->required()->maxLength(3),
                TextInput::make('idempotency_key')->label(Display::field('idempotency_key'))->required()->maxLength(191),
            ]))
            ->tableAction(static function (array $data): void {
                DomainAction::attempt('new-payout-batch', MlmPermission::PAYOUTS_OPERATE, [ConflictingPayoutBatch::class, InvalidCurrencyCode::class, InvalidPayoutBatch::class], static fn (): PayoutBatch => app(PayoutBatchManager::class)->create(
                    Program::query()->findOrFail($data['program_id'] ?? null),
                    (string) ($data['currency'] ?? ''),
                    (string) ($data['idempotency_key'] ?? ''),
                ));
            });
    }

    /**
     * An approved request of the batch's program and currency, at the end
     * of an open batch. Membership is never removed; sealing fixes it.
     */
    public static function addRequest(): Action
    {
        return DomainAction::withForm('add-request', MlmPermission::PAYOUTS_OPERATE)
            ->icon('plus')
            ->visible(static fn (?Model $batch = null): bool => $batch instanceof PayoutBatch && $batch->status === PayoutBatchStatus::Open)
            ->schema(static fn (?Model $batch = null): FormSchema => FormSchema::make()->schema([
                Select::make('payout_request_id')
                    ->label(Display::field('payout_request'))
                    ->helperText(__('mlm::mlm.helpers.batch_candidates'))
                    ->existsIn(...Options::exists(PayoutRequest::class))
                    ->optionsUsing(static fn (): array => $batch instanceof PayoutBatch ? Options::batchCandidates($batch) : [])
                    ->required(),
            ]))
            ->action(static function (PayoutBatch $batch, array $data = []): void {
                DomainAction::attempt('add-request', MlmPermission::PAYOUTS_OPERATE, [InvalidPayoutBatch::class], static fn () => app(PayoutBatchManager::class)->add(
                    $batch,
                    PayoutRequest::query()->findOrFail($data['payout_request_id'] ?? null),
                ));
            });
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
                DomainAction::attempt($name, MlmPermission::PAYOUTS_OPERATE, [InvalidPayoutBatch::class], static fn () => $step($batch, CarbonImmutable::now()));
            });
    }
}
