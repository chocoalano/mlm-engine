<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Commission;

use PandaBear\Mlm\Exceptions\InvalidPlanDefinition;
use PandaBear\Mlm\Models\LedgerAccount;
use PandaBear\Mlm\Models\PlanComponent;

/**
 * @internal
 *
 * Whether one source account funds a set of commission components — the
 * rule a hybrid batch (ADR-028) and a commission period (ADR-029) share:
 * a system account of the program, in the currency and under the key each
 * component names as its own source account. One account, so one
 * currency.
 */
final class CommissionFunding
{
    /**
     * Why the account cannot fund the components, or null when it can.
     *
     * @param  iterable<PlanComponent>  $components
     */
    public static function problem(string $connection, string $programId, string $accountId, iterable $components): ?string
    {
        $account = LedgerAccount::on($connection)->find($accountId);

        if ($account === null) {
            return 'it does not exist.';
        }

        if ($account->program_id !== $programId) {
            return "it belongs to program [{$account->program_id}], not the plan's program [{$programId}].";
        }

        if ($account->wallet_id !== null) {
            return "it is the account of wallet [{$account->wallet_id}], not a system account.";
        }

        foreach ($components as $component) {
            try {
                $parameters = CommissionComponentParameters::parse($component->parameters);
            } catch (InvalidPlanDefinition $exception) {
                return "component \"{$component->key}\" has no valid funding: {$exception->getMessage()}";
            }

            if ($parameters->sourceAccount !== $account->key || $parameters->currency->value() !== $account->currency) {
                return sprintf(
                    'component "%s" is funded from "%s" in %s, not "%s" in %s; every component is funded from one account.',
                    $component->key,
                    $parameters->sourceAccount,
                    $parameters->currency->value(),
                    $account->key,
                    $account->currency,
                );
            }
        }

        return null;
    }
}
