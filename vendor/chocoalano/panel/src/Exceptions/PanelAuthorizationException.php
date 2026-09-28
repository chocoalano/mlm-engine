<?php

declare(strict_types=1);

namespace PandaPanel\Exceptions;

use RuntimeException;

/**
 * Thrown under `strictAuthorization()` when an ability cannot be answered by
 * a policy.
 *
 * A missing policy and a policy that refuses look identical from the outside:
 * both are a 403. That is correct in production and unhelpful while building,
 * where a forgotten policy reads as a working authorization rule. Strict mode
 * separates the two.
 */
final class PanelAuthorizationException extends RuntimeException
{
    /**
     * @param  class-string  $model
     */
    public static function missingPolicy(string $model, string $ability): self
    {
        return new self(
            "No policy is registered for [{$model}], so the ability [{$ability}] can only ever be denied. "
            .'Register one, or turn off strictAuthorization() for this panel.'
        );
    }

    /**
     * @param  class-string  $policy
     * @param  class-string  $model
     */
    public static function missingPolicyMethod(string $policy, string $model, string $ability): self
    {
        return new self(
            "The policy [{$policy}] for [{$model}] does not define [{$ability}], so that ability can only ever be denied. "
            .'Add the method, or turn off strictAuthorization() for this panel.'
        );
    }
}
