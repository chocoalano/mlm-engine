<?php

declare(strict_types=1);

namespace PandaPanel\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Says why a resource is not in the sidebar when the reason is a policy that
 * was never written.
 *
 * The symptom is identical to three other problems: a resource registered in
 * the wrong panel, a resource whose `canViewAny()` genuinely says no, and a
 * panel manifest that predates the resource. All four look like "my resource
 * is missing", and none of them said anything.
 *
 * This covers the one of the four that is a mistake rather than a decision.
 * `Gate::allows()` answers false when no policy exists at all, which is
 * correct — deny is the safe direction — but indistinguishable from a policy
 * that considered the question and said no.
 *
 * A log line rather than an exception, and only in development. The framework
 * already has the strict version of this: `Panel::strictAuthorization()` turns
 * a missing policy into a `PanelAuthorizationException` everywhere the panel
 * asks, including the relation abilities that have no `can*` method. This
 * exists so that somebody who has not turned that on finds out it is there,
 * at the moment they are staring at the gap it explains.
 */
final class MissingPolicyNotice
{
    /**
     * Models already reported, so one missing policy is one line rather than
     * one per navigation build per request.
     *
     * @var array<class-string<Model>, true>
     */
    private static array $reported = [];

    /**
     * @param  class-string<Model>  $model
     */
    public static function reportIfMissing(string $resource, string $model): void
    {
        if (! self::shouldReport($model)) {
            return;
        }

        self::$reported[$model] = true;

        Log::debug(sprintf(
            '[panel] %s is not in the navigation because %s has no policy, so viewAny() is '
                .'denied by default. Create one with `php artisan make:policy %sPolicy '
                .'--model=%s`, or say so on the resource by overriding canViewAny(). '
                .'Panel::strictAuthorization() turns this into an exception everywhere the '
                .'panel asks, which is worth having in development.',
            class_basename($resource),
            class_basename($model),
            class_basename($model),
            class_basename($model),
        ));
    }

    /** Only for tests, which build navigation many times over. */
    public static function forget(): void
    {
        self::$reported = [];
    }

    /**
     * @param  class-string<Model>  $model
     */
    private static function shouldReport(string $model): bool
    {
        if (isset(self::$reported[$model])) {
            return false;
        }

        try {
            // Never in production. A resource deliberately hidden from every
            // user is a legitimate arrangement, and a log line per deploy
            // about it is noise in the one place noise is expensive.
            if (! app()->hasDebugModeEnabled() && ! app()->environment('local', 'testing')) {
                return false;
            }

            // A policy that exists and said no is a decision, not a mistake.
            return Gate::getPolicyFor($model) === null;
        } catch (Throwable) {
            // Asking the container about the environment or the gate must
            // never be the reason a sidebar fails to render.
            return false;
        }
    }

    /**
     * The policy class an application would be expected to write, for the
     * message above. Not resolved — it is a suggestion, and the file does not
     * exist, which is the point.
     */
    public static function expectedPolicy(string $model): string
    {
        return Str::of($model)
            ->replace('\\Models\\', '\\Policies\\')
            ->append('Policy')
            ->toString();
    }
}
