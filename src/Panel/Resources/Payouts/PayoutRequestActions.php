<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Payouts;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use PandaBear\Mlm\Exceptions\ConflictingPayoutRequest;
use PandaBear\Mlm\Exceptions\InsufficientPayoutBalance;
use PandaBear\Mlm\Exceptions\InvalidPayoutRequest;
use PandaBear\Mlm\Exceptions\InvalidPayoutTransition;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\Member;
use PandaBear\Mlm\Models\PayoutRequest;
use PandaBear\Mlm\Models\Wallet;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Support\Display;
use PandaBear\Mlm\Panel\Support\DomainAction;
use PandaBear\Mlm\Panel\Support\Options;
use PandaBear\Mlm\Payout\PayoutManager;
use PandaBear\Mlm\Payout\PayoutRequestStatus;
use PandaPanel\Actions\Action;
use PandaPanel\Forms\Components\DateTimePicker;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\Components\Textarea;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Support\FormState;

/**
 * A payout request's lifecycle, each step one call to `PayoutManager` — the
 * only writer of payout requests. Offered where the request's status has
 * that step next; the manager decides, under its locks, whether it may.
 */
final class PayoutRequestActions
{
    private const REFUSALS = [InvalidPayoutTransition::class, ConflictingPayoutRequest::class, InvalidPayoutRequest::class];

    /**
     * @return list<Action>
     */
    public static function all(): array
    {
        return [self::approve(), self::startProcessing(), self::settle(), self::fail(), self::cancel()];
    }

    public static function approve(): Action
    {
        return DomainAction::confirmed('approve', MlmPermission::PAYOUTS_OPERATE)
            ->icon('check')
            ->visible(self::next(PayoutRequestStatus::Approved))
            ->action(static function (PayoutRequest $request): void {
                DomainAction::attempt(
                    'approve',
                    MlmPermission::PAYOUTS_OPERATE,
                    [InsufficientPayoutBalance::class, ...self::REFUSALS],
                    static fn () => app(PayoutManager::class)->approve($request, CarbonImmutable::now()),
                );
            });
    }

    /**
     * For a standalone request. One in a batch starts with its batch — the
     * manager refuses it here, and that refusal is what the operator sees.
     */
    public static function startProcessing(): Action
    {
        return DomainAction::confirmed('start-processing', MlmPermission::PAYOUTS_OPERATE)
            ->icon('upload')
            ->visible(self::next(PayoutRequestStatus::Processing))
            ->action(static function (PayoutRequest $request): void {
                DomainAction::attempt(
                    'start-processing',
                    MlmPermission::PAYOUTS_OPERATE,
                    self::REFUSALS,
                    static fn () => app(PayoutManager::class)->startProcessing($request, CarbonImmutable::now()),
                );
            });
    }

    public static function settle(): Action
    {
        return DomainAction::withForm('settle', MlmPermission::PAYOUTS_OPERATE)
            ->icon('check')
            ->visible(self::next(PayoutRequestStatus::Settled))
            ->schema(static fn (?Model $request = null): FormSchema => FormSchema::make()->schema([
                TextInput::make('settlement_reference')
                    ->label(__('mlm::mlm.actions.settle.reference'))
                    ->required()
                    ->maxLength(191),
                self::moment('settled_at', __('mlm::mlm.actions.settle.moment')),
            ]))
            ->action(static function (PayoutRequest $request, array $data = []): void {
                DomainAction::attempt(
                    'settle',
                    MlmPermission::PAYOUTS_OPERATE,
                    self::REFUSALS,
                    static fn () => app(PayoutManager::class)->settle(
                        $request,
                        (string) ($data['settlement_reference'] ?? ''),
                        CarbonImmutable::parse((string) ($data['settled_at'] ?? 'now')),
                    ),
                );
            });
    }

    public static function fail(): Action
    {
        return DomainAction::withForm('fail', MlmPermission::PAYOUTS_OPERATE)
            ->icon('triangle-alert')
            ->visible(self::next(PayoutRequestStatus::Failed))
            ->schema(static fn (?Model $request = null): FormSchema => FormSchema::make()->schema([
                Textarea::make('failure_reason')
                    ->label(__('mlm::mlm.actions.fail.reason'))
                    ->required()
                    ->maxLength(255),
                self::moment('failed_at', __('mlm::mlm.actions.fail.moment')),
            ]))
            ->action(static function (PayoutRequest $request, array $data = []): void {
                DomainAction::attempt(
                    'fail',
                    MlmPermission::PAYOUTS_OPERATE,
                    self::REFUSALS,
                    static fn () => app(PayoutManager::class)->fail(
                        $request,
                        (string) ($data['failure_reason'] ?? ''),
                        CarbonImmutable::parse((string) ($data['failed_at'] ?? 'now')),
                    ),
                );
            });
    }

    public static function cancel(): Action
    {
        return DomainAction::withForm('cancel', MlmPermission::PAYOUTS_OPERATE)
            ->icon('x')
            ->visible(self::next(PayoutRequestStatus::Cancelled))
            ->schema(static fn (?Model $request = null): FormSchema => FormSchema::make()->schema([
                Textarea::make('reason')
                    ->label(__('mlm::mlm.actions.cancel.reason'))
                    ->maxLength(255),
            ]))
            ->action(static function (PayoutRequest $request, array $data = []): void {
                $reason = trim((string) ($data['reason'] ?? ''));

                DomainAction::attempt(
                    'cancel',
                    MlmPermission::PAYOUTS_OPERATE,
                    self::REFUSALS,
                    static fn () => app(PayoutManager::class)->cancel($request, CarbonImmutable::now(), $reason === '' ? null : $reason),
                );
            });
    }

    /**
     * A request from a member's wallet, settled through a system account of
     * the wallet's program and currency. The program and currency come from
     * the wallet. Nothing moves and no balance is checked yet: the balance
     * is read, and the funds reserved, when the request is approved.
     */
    public static function creation(): Action
    {
        return DomainAction::withForm('new-payout-request', MlmPermission::PAYOUTS_OPERATE)
            ->icon('plus')
            ->schema(static fn (): FormSchema => FormSchema::make()->schema([
                Select::make('member_id')
                    ->label(Display::field('member'))
                    ->searchable()
                    ->live()
                    ->existsIn(...Options::exists(Member::class))
                    ->optionsUsing(static fn (FormState $state, ?string $search = null): array => Options::anyMembers($search))
                    ->required(),
                Select::make('wallet_id')
                    ->label(Display::field('wallet'))
                    ->helperText(__('mlm::mlm.helpers.wallet_balance'))
                    ->live()
                    ->existsIn(...Options::exists(Wallet::class))
                    ->optionsUsing(static fn (FormState $state): array => Options::walletsOf($state->get('member_id')))
                    ->required(),
                Select::make('settlement_ledger_account_id')
                    ->label(Display::field('settlement_account'))
                    ->existsIn(...Options::exists(LedgerAccount::class))
                    ->optionsUsing(static fn (FormState $state): array => Options::settlementAccountsFor($state->get('wallet_id')))
                    ->required(),
                TextInput::make('amount')->label(Display::field('amount'))->required()->maxLength(40),
                TextInput::make('destination_type')->label(Display::field('destination_type'))->required()->maxLength(64),
                TextInput::make('destination_reference')->label(Display::field('destination_reference'))->helperText(__('mlm::mlm.helpers.destination_reference'))->required()->maxLength(191),
                self::moment('requested_at', Display::field('requested_at')),
                TextInput::make('idempotency_key')->label(Display::field('idempotency_key'))->required()->maxLength(191),
            ]))
            ->tableAction(static function (array $data): void {
                DomainAction::attempt('new-payout-request', MlmPermission::PAYOUTS_OPERATE, self::REFUSALS, static fn (): PayoutRequest => app(PayoutManager::class)->request(
                    member: Member::query()->findOrFail($data['member_id'] ?? null),
                    wallet: Wallet::query()->findOrFail($data['wallet_id'] ?? null),
                    settlementAccount: LedgerAccount::query()->findOrFail($data['settlement_ledger_account_id'] ?? null),
                    amount: (string) ($data['amount'] ?? ''),
                    destinationType: (string) ($data['destination_type'] ?? ''),
                    destinationReference: (string) ($data['destination_reference'] ?? ''),
                    requestedAt: CarbonImmutable::parse((string) ($data['requested_at'] ?? 'now')),
                    idempotencyKey: (string) ($data['idempotency_key'] ?? ''),
                ));
            });
    }

    /**
     * Whether the request's status has `$step` next — the status's own
     * answer, not a rule restated here.
     *
     * @return \Closure(?Model): bool
     */
    private static function next(PayoutRequestStatus $step): \Closure
    {
        return static fn (?Model $request = null): bool => $request instanceof PayoutRequest && $request->status->canTransitionTo($step);
    }

    private static function moment(string $name, string $label): DateTimePicker
    {
        return DateTimePicker::make($name)
            ->label($label)
            ->seconds()
            ->required()
            ->rules(['date'])
            ->default(CarbonImmutable::now()->format('Y-m-d H:i:s'));
    }
}
