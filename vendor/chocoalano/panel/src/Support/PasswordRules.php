<?php

declare(strict_types=1);

namespace PandaPanel\Support;

use Illuminate\Validation\Rules\Password;

/**
 * The application's password policy, as a browser `passwordrules` attribute.
 *
 * Safari and iOS read that attribute when generating a suggested password, so
 * a policy expressed only in server-side validation produces a suggestion the
 * form then rejects. Sending it to the frontend is what makes the two agree.
 *
 * Laravel 13 has `Password::toPasswordRulesString()`. Laravel 12 does not —
 * and calling it there is a 500 on the security settings page, which is the
 * kind of breakage a version constraint is supposed to prevent and a test
 * matrix is supposed to catch.
 *
 * Both versions expose `appliedRules()`, with the same keys, so the fallback
 * is a faithful reimplementation rather than an approximation: the string
 * below is byte-for-byte what Laravel 13 produces from the same policy.
 */
final class PasswordRules
{
    /**
     * The method Laravel 13 has and Laravel 12 does not.
     *
     * Named here so the one place that asks for it and the one place that
     * calls it cannot disagree. See fromFramework() for why the asking is
     * done through a plain object.
     */
    private const FRAMEWORK_METHOD = 'toPasswordRulesString';

    public static function attribute(?Password $password = null): string
    {
        $password ??= Password::defaults();

        // The framework's own, whenever the framework has one. A future
        // change to what Safari accepts should be picked up from Laravel
        // rather than reimplemented here a second time.
        return self::fromFramework($password) ?? self::fromAppliedRules($password);
    }

    /**
     * Laravel's own answer, or null on a version that has none.
     *
     * The parameter is `object` rather than `Password` on purpose. Whether
     * this method exists is a fact about the installed framework, and a
     * checker analysing one major is certain about it in a way that is wrong
     * for the other — on 13 the guard reads as dead code, on 12 the call
     * reads as an error. Asking the question of a plain object is the honest
     * way to say "this depends on what is installed", which is exactly what
     * a package spanning two majors means.
     */
    private static function fromFramework(object $password): ?string
    {
        if (! method_exists($password, self::FRAMEWORK_METHOD)) {
            return null;
        }

        return (string) $password->{self::FRAMEWORK_METHOD}();
    }

    private static function fromAppliedRules(Password $password): string
    {
        $applied = $password->appliedRules();

        $min = $applied['min'] ?? 8;
        $rules = ['minlength: '.(is_int($min) || is_string($min) ? $min : 8)];

        if (($applied['max'] ?? null) !== null && $applied['max'] !== false) {
            $rules[] = 'maxlength: '.$applied['max'];
        }

        // `mixedCase` implies letters, so the two are one branch rather than
        // two — a policy requiring mixed case that also emitted a bare
        // `required: lower` would say the same thing twice.
        if (($applied['mixedCase'] ?? false) === true) {
            $rules[] = 'required: lower';
            $rules[] = 'required: upper';
        } elseif (($applied['letters'] ?? false) === true) {
            $rules[] = 'required: lower';
        }

        if (($applied['numbers'] ?? false) === true) {
            $rules[] = 'required: digit';
        }

        if (($applied['symbols'] ?? false) === true) {
            $rules[] = 'required: special';
        }

        return implode('; ', $rules).';';
    }
}
