<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel;

use Illuminate\Support\Facades\Gate;

/**
 * The capabilities the Panda Panel surface asks for (ADR-031).
 *
 * Asked as abilities through Laravel's Gate, never as roles: an application
 * grants them however it grants abilities — `Gate::define()`, a
 * `Gate::before()` hook, or a permission package whose `can()` answers
 * for them. Which roles hold which capability is the application's choice.
 *
 * `view` capabilities read; `operate` capabilities run lifecycle actions
 * through the domain services, and imply nothing about viewing — an
 * application grants both to an operator.
 */
final class MlmPermission
{
    public const DASHBOARD_VIEW = 'mlm.dashboard.view';

    public const PROGRAMS_VIEW = 'mlm.programs.view';

    public const MEMBERS_VIEW = 'mlm.members.view';

    public const PLANS_VIEW = 'mlm.plans.view';

    public const PERIODS_VIEW = 'mlm.periods.view';

    public const PERIODS_OPERATE = 'mlm.periods.operate';

    public const CALCULATIONS_VIEW = 'mlm.calculations.view';

    public const COMMISSIONS_VIEW = 'mlm.commissions.view';

    public const WALLETS_VIEW = 'mlm.wallets.view';

    public const LEDGER_VIEW = 'mlm.ledger.view';

    public const PAYOUTS_VIEW = 'mlm.payouts.view';

    public const PAYOUTS_OPERATE = 'mlm.payouts.operate';

    /**
     * The catalogue, for an application seeding its own permission store.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [...self::view(), ...self::operate()];
    }

    /**
     * @return list<string>
     */
    public static function view(): array
    {
        return [
            self::DASHBOARD_VIEW,
            self::PROGRAMS_VIEW,
            self::MEMBERS_VIEW,
            self::PLANS_VIEW,
            self::PERIODS_VIEW,
            self::CALCULATIONS_VIEW,
            self::COMMISSIONS_VIEW,
            self::WALLETS_VIEW,
            self::LEDGER_VIEW,
            self::PAYOUTS_VIEW,
        ];
    }

    /**
     * The capabilities that change financial state, through domain services.
     *
     * @return list<string>
     */
    public static function operate(): array
    {
        return [
            self::PERIODS_OPERATE,
            self::PAYOUTS_OPERATE,
        ];
    }

    /**
     * Whether the current user holds the capability. A guest holds none.
     */
    public static function allows(string $permission): bool
    {
        return Gate::allows($permission);
    }
}
