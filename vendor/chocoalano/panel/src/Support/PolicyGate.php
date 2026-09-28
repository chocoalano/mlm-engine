<?php

declare(strict_types=1);

namespace PandaPanel\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use PandaPanel\Exceptions\PanelAuthorizationException;

/**
 * The one place a panel ability is asked, so strict authorization is one
 * check rather than one per caller.
 *
 * `Resource::authorize()` and `RelationManager::authorize()` both delegate
 * here. Neither calls `Gate::allows()` directly, which is what keeps the
 * strict-mode guarantee — that a missing policy fails loudly instead of
 * reading as a working deny — true for every ability the panel asks about,
 * including the relation abilities that have no `can*` method on a resource.
 */
final class PolicyGate
{
    /**
     * @param  Model|class-string  $subject  what the policy is resolved from
     * @param  list<mixed>  $arguments  extra arguments the policy method takes, such as the related record of an attach
     *
     * @throws PanelAuthorizationException
     */
    public static function allows(string $ability, Model|string $subject, array $arguments = []): bool
    {
        if (panel()?->hasStrictAuthorization() === true) {
            self::assertPolicyAnswers($ability, $subject);
        }

        return Gate::allows($ability, $arguments === [] ? $subject : [$subject, ...$arguments]);
    }

    /**
     * @param  Model|class-string  $subject
     *
     * @throws PanelAuthorizationException
     */
    private static function assertPolicyAnswers(string $ability, Model|string $subject): void
    {
        $policy = Gate::getPolicyFor($subject);
        $model = $subject instanceof Model ? $subject::class : $subject;

        if ($policy === null) {
            throw PanelAuthorizationException::missingPolicy($model, $ability);
        }

        // `before` may answer for every ability, in which case a missing
        // method is not a mistake.
        if (method_exists($policy, 'before')) {
            return;
        }

        if (! method_exists($policy, $ability)) {
            throw PanelAuthorizationException::missingPolicyMethod($policy::class, $model, $ability);
        }
    }
}
