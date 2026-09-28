<?php

declare(strict_types=1);

namespace PandaPanel\Auth;

use Illuminate\Database\Eloquent\Model;

/**
 * Whether an account has the emailed-code factor turned on.
 *
 * Read out of the raw attributes rather than through `getAttribute()`. This
 * application has `preventAccessingMissingAttributes()` on, and a user loaded
 * with a narrowed select — a table widget picking four columns — would throw
 * rather than answer. "Was not selected" is not "is turned on", and the
 * honest answer to a column nobody asked for is no.
 */
final class EmailCodeFactor
{
    public static function isEnabledFor(?object $user): bool
    {
        if (! $user instanceof Model) {
            return false;
        }

        return ($user->getAttributes()['two_factor_email_confirmed_at'] ?? null) !== null;
    }
}
