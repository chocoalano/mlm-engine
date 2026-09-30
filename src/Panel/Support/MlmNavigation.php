<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Support;

/**
 * The one sidebar group every Panda MLM entry sits in, and their order.
 */
final class MlmNavigation
{
    public const OVERVIEW = 0;

    public const PROGRAMS = 10;

    public const MEMBERS = 11;

    public const GENEALOGY = 12;

    public const PLANS = 20;

    public const PERIODS = 21;

    public const RUNS = 22;

    public const COMMISSIONS = 23;

    public const WALLETS = 30;

    public const PAYOUT_REQUESTS = 31;

    public const PAYOUT_BATCHES = 32;

    public static function group(): string
    {
        return __('mlm::mlm.navigation.group');
    }
}
