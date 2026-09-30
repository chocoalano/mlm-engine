<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Periods;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use PandaBear\Mlm\Exceptions\InvalidCalculationRun;
use PandaBear\Mlm\Exceptions\InvalidCommissionPeriod;
use PandaBear\Mlm\Exceptions\InvalidCommissionPeriodTransition;
use PandaBear\Mlm\Exceptions\InvalidHybridCalculation;
use PandaBear\Mlm\Models\CommissionPeriod;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Support\DomainAction;
use PandaBear\Mlm\Period\CommissionPeriodCalculator;
use PandaBear\Mlm\Period\CommissionPeriodFinalizer;
use PandaBear\Mlm\Period\CommissionPeriodReleaser;
use PandaBear\Mlm\Period\CommissionPeriodStatus;
use PandaPanel\Actions\Action;
use PandaPanel\Forms\Components\DateTimePicker;
use PandaPanel\Forms\FormSchema;

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
                    [InvalidCommissionPeriodTransition::class],
                    static fn () => app(CommissionPeriodReleaser::class)->release($period, CarbonImmutable::parse((string) ($data['released_at'] ?? 'now'))),
                );
            });
    }

    private static function in(?Model $period, CommissionPeriodStatus $status): bool
    {
        return $period instanceof CommissionPeriod && $period->status === $status;
    }
}
