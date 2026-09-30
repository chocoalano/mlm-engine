<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Commissions;

use Closure;
use Illuminate\Database\Eloquent\Model;
use PandaBear\Mlm\Commission\CommissionLifecycle;
use PandaBear\Mlm\Commission\CommissionPoster;
use PandaBear\Mlm\Commission\CommissionStatus;
use PandaBear\Mlm\Exceptions\CommissionPeriodNotReleased;
use PandaBear\Mlm\Exceptions\IncompleteCalculationBatch;
use PandaBear\Mlm\Exceptions\InvalidCommissionTransition;
use PandaBear\Mlm\Exceptions\UnresolvedBinaryCorrection;
use PandaBear\Mlm\Models\Commission;
use PandaBear\Mlm\Panel\MlmPermission;
use PandaBear\Mlm\Panel\Support\DomainAction;
use PandaPanel\Actions\Action;

/**
 * A commission's review and posting: `CommissionLifecycle` marks it pending,
 * approves or cancels it; `CommissionPoster` posts it — the one step here
 * that moves money, into the member's wallet through the ledger. Each is
 * offered where the commission's status has it next; the services decide.
 */
final class CommissionActions
{
    /**
     * @return list<Action>
     */
    public static function all(): array
    {
        return [
            self::step('mark-pending', 'check', CommissionStatus::Pending, static fn (Commission $commission): Commission => app(CommissionLifecycle::class)->markPending($commission)),
            self::step('approve-commission', 'check', CommissionStatus::Approved, static fn (Commission $commission): Commission => app(CommissionLifecycle::class)->approve($commission)),
            self::step('post-commission', 'upload', CommissionStatus::Posted, static fn (Commission $commission): Commission => app(CommissionPoster::class)->post($commission)),
            self::step('cancel-commission', 'x', CommissionStatus::Cancelled, static fn (Commission $commission): Commission => app(CommissionLifecycle::class)->cancel($commission)),
        ];
    }

    /**
     * @param  Closure(Commission): Commission  $step
     */
    private static function step(string $name, string $icon, CommissionStatus $to, Closure $step): Action
    {
        return DomainAction::confirmed($name, MlmPermission::COMMISSIONS_OPERATE)
            ->icon($icon)
            ->visible(static fn (?Model $commission = null): bool => $commission instanceof Commission && $commission->status->canTransitionTo($to))
            ->action(static function (Commission $commission) use ($name, $step): void {
                DomainAction::attempt($name, MlmPermission::COMMISSIONS_OPERATE, [
                    InvalidCommissionTransition::class,
                    CommissionPeriodNotReleased::class,
                    IncompleteCalculationBatch::class,
                    UnresolvedBinaryCorrection::class,
                ], static fn (): Commission => $step($commission));
            });
    }
}
