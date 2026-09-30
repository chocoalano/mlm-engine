<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Periods;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use PandaBear\Mlm\Exceptions\ConflictingCommissionPeriod;
use PandaBear\Mlm\Exceptions\InvalidCalculationRun;
use PandaBear\Mlm\Exceptions\InvalidCommissionPeriod;
use PandaBear\Mlm\Exceptions\InvalidCommissionPeriodTransition;
use PandaBear\Mlm\Exceptions\InvalidHybridCalculation;
use PandaBear\Mlm\Models\CommissionPeriod;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\PlanVersion;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Support\Display;
use PandaBear\Mlm\Panel\Support\DomainAction;
use PandaBear\Mlm\Panel\Support\Options;
use PandaBear\Mlm\Period\CommissionPeriodCalculator;
use PandaBear\Mlm\Period\CommissionPeriodFinalizer;
use PandaBear\Mlm\Period\CommissionPeriodManager;
use PandaBear\Mlm\Period\CommissionPeriodReleaser;
use PandaBear\Mlm\Period\CommissionPeriodStatus;
use PandaPanel\Actions\Action;
use PandaPanel\Forms\Components\DateTimePicker;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Support\FormState;

/**
 * A period's lifecycle, each step one call to its period service — the
 * public orchestration boundary, never the calculation engines beneath it.
 *
 * Offered only in the status the step starts from; whether it may happen
 * is the service's answer, under its own locks.
 */
final class PeriodActions
{
    /**
     * @return list<Action>
     */
    public static function all(): array
    {
        return [self::calculate(), self::finalize(), self::release()];
    }

    public static function calculate(): Action
    {
        return DomainAction::confirmed('calculate', MlmPermission::PERIODS_OPERATE)
            ->icon('rotate-ccw')
            ->visible(static fn (?Model $period = null): bool => self::in($period, CommissionPeriodStatus::Open))
            ->action(static function (CommissionPeriod $period): void {
                DomainAction::attempt(
                    'calculate',
                    MlmPermission::PERIODS_OPERATE,
                    [InvalidCommissionPeriod::class, InvalidCalculationRun::class, InvalidHybridCalculation::class],
                    static fn () => app(CommissionPeriodCalculator::class)->calculate($period),
                );
            });
    }

    public static function finalize(): Action
    {
        return DomainAction::confirmed('finalize', MlmPermission::PERIODS_OPERATE)
            ->icon('check')
            ->visible(static fn (?Model $period = null): bool => self::in($period, CommissionPeriodStatus::Calculated))
            ->action(static function (CommissionPeriod $period): void {
                DomainAction::attempt(
                    'finalize',
                    MlmPermission::PERIODS_OPERATE,
                    [InvalidCommissionPeriodTransition::class],
                    static fn () => app(CommissionPeriodFinalizer::class)->finalize($period),
                );
            });
    }

    /**
     * The releaser takes the release moment from its caller, so the
     * operator states it; it defaults to now.
     */
    public static function release(): Action
    {
        return DomainAction::withForm('release', MlmPermission::PERIODS_OPERATE)
            ->icon('unlink')
            ->visible(static fn (?Model $period = null): bool => self::in($period, CommissionPeriodStatus::Finalized))
            ->schema(static fn (?Model $period = null): FormSchema => FormSchema::make()->schema([
                DateTimePicker::make('released_at')
                    ->label(__('mlm::mlm.actions.release.moment'))
                    ->seconds()
                    ->required()
                    ->rules(['date'])
                    ->default(CarbonImmutable::now()->format('Y-m-d H:i:s')),
            ]))
            ->action(static function (CommissionPeriod $period, array $data = []): void {
                DomainAction::attempt(
                    'release',
                    MlmPermission::PERIODS_OPERATE,
                    [InvalidCommissionPeriodTransition::class],
                    static fn () => app(CommissionPeriodReleaser::class)->release($period, CarbonImmutable::parse((string) ($data['released_at'] ?? 'now'))),
                );
            });
    }

    /**
     * A new period over the program's active plan version, funded by one of
     * its system accounts. Its range, release moment and overlap with the
     * program's other periods are the period manager's to check.
     */
    public static function open(): Action
    {
        return DomainAction::withForm('open-period', MlmPermission::PERIODS_OPERATE)
            ->icon('plus')
            ->schema(static fn (): FormSchema => FormSchema::make()->schema([
                Select::make('program_id')
                    ->label(Display::field('program'))
                    ->searchable()
                    ->live()
                    ->existsIn(...Options::exists(Program::class))
                    ->optionsUsing(static fn (FormState $state, ?string $search = null): array => Options::programs($search))
                    ->required(),
                Select::make('plan_version_id')
                    ->label(Display::field('plan_version'))
                    ->existsIn(...Options::exists(PlanVersion::class))
                    ->optionsUsing(static fn (FormState $state): array => Options::activeVersions($state->get('program_id')))
                    ->required(),
                Select::make('source_ledger_account_id')
                    ->label(Display::field('source_account'))
                    ->existsIn(...Options::exists(LedgerAccount::class))
                    ->optionsUsing(static fn (FormState $state): array => Options::systemAccounts($state->get('program_id')))
                    ->required(),
                DateTimePicker::make('from_at')->label(Display::field('from_at'))->seconds()->required()->rules(['date']),
                DateTimePicker::make('until_at')->label(Display::field('until_at'))->seconds()->required()->rules(['date']),
                DateTimePicker::make('release_at')->label(Display::field('release_at'))->seconds()->required()->rules(['date']),
                TextInput::make('idempotency_key')->label(Display::field('idempotency_key'))->required()->maxLength(191),
            ]))
            ->tableAction(static function (array $data): void {
                DomainAction::attempt('open-period', MlmPermission::PERIODS_OPERATE, [InvalidCommissionPeriod::class, ConflictingCommissionPeriod::class], static fn (): CommissionPeriod => app(CommissionPeriodManager::class)->create(
                    Program::query()->findOrFail($data['program_id'] ?? null),
                    PlanVersion::query()->findOrFail($data['plan_version_id'] ?? null),
                    LedgerAccount::query()->findOrFail($data['source_ledger_account_id'] ?? null),
                    CarbonImmutable::parse((string) $data['from_at']),
                    CarbonImmutable::parse((string) $data['until_at']),
                    CarbonImmutable::parse((string) $data['release_at']),
                    (string) ($data['idempotency_key'] ?? ''),
                ));
            });
    }

    private static function in(?Model $period, CommissionPeriodStatus $status): bool
    {
        return $period instanceof CommissionPeriod && $period->status === $status;
    }
}
