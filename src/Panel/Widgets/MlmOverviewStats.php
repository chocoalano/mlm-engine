<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Widgets;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use PandaBear\Mlm\Commission\CommissionStatus;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Models\CommissionPeriod;
use PandaBear\Mlm\Models\PayoutRequest;
use PandaBear\Mlm\Models\Program;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Payout\PayoutRequestStatus;
use PandaBear\Mlm\Period\CommissionPeriodStatus;
use PandaBear\Mlm\Planning\PlanVersionStatus;
use PandaPanel\Widgets\Enums\StatColor;
use PandaPanel\Widgets\StatsWidget;
use PandaPanel\Widgets\Support\Stat;

/**
 * Four counts, each a queue an operator can act on. Counts rather than
 * amounts: programs may pay in different currencies, and one figure summed
 * across them would be no amount at all.
 *
 * Every figure is one aggregate query; no genealogy is read, and nothing is
 * ever acted on from here.
 */
final class MlmOverviewStats extends StatsWidget
{
    protected static int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return MlmPermission::allows(MlmPermission::DASHBOARD_VIEW);
    }

    /**
     * @return list<Stat>
     */
    public function stats(): array
    {
        $periods = static fn (): Builder => CommissionPeriod::query();
        $calculated = $periods()->where('status', CommissionPeriodStatus::Calculated->value)->count();
        $releasable = $periods()->where('status', CommissionPeriodStatus::Finalized->value)->where('release_at', '<=', CarbonImmutable::now())->count();
        $stalled = $periods()->where('status', CommissionPeriodStatus::Open->value)->whereNotNull('input_closed_at')->count();
        $requested = PayoutRequest::query()->where('status', PayoutRequestStatus::Requested->value)->count();
        $processing = PayoutRequest::query()->where('status', PayoutRequestStatus::Processing->value)->count();

        return [
            Stat::make(__('mlm::mlm.overview.stats.programs'), Program::query()
                ->whereHas('plans.versions', static fn (Builder $versions): Builder => $versions->where('status', PlanVersionStatus::Active->value))
                ->count())
                ->description(__('mlm::mlm.overview.stats.programs_description'))
                ->icon('settings'),
            Stat::make(__('mlm::mlm.overview.stats.periods'), $calculated + $releasable + $stalled)
                ->description(__('mlm::mlm.overview.stats.periods_description', ['calculated' => $calculated, 'releasable' => $releasable, 'stalled' => $stalled]))
                ->icon('rotate-ccw')
                ->color($stalled > 0 ? StatColor::Warning : StatColor::Default),
            Stat::make(__('mlm::mlm.overview.stats.available'), Commission::query()->where('status', CommissionStatus::Available->value)->count())
                ->description(__('mlm::mlm.overview.stats.available_description'))
                ->icon('check'),
            Stat::make(__('mlm::mlm.overview.stats.payouts'), $requested + $processing)
                ->description(__('mlm::mlm.overview.stats.payouts_description', ['requested' => $requested, 'processing' => $processing]))
                ->icon('upload'),
        ];
    }
}
