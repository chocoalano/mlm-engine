<?php

declare(strict_types=1);

namespace PandaPanel\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Throwable;

/**
 * The relations a set of dotted names has to read.
 *
 * A column, entry or export column named `author.name` reads its value with
 * `data_get()`, and `data_get()` on an unloaded relation loads it — once per
 * record. That is the N+1 every table has, and until now the only defence was
 * remembering to declare `$with`. Nothing derived it, nothing detected it, and
 * the symptom was a page that worked and got slower with the data.
 *
 * So it is derived. `author.name` asks for `author`, `author.company.name`
 * asks for `author.company`, and a name with no dot asks for nothing.
 *
 * **Best effort, and never fatal.** Adding an eager load can only reduce the
 * number of queries — the relation was going to be read anyway — but *getting
 * it wrong* must not be able to break a page that works today. So every
 * segment is verified against the model before it is claimed, and anything
 * unverifiable is dropped rather than guessed at: a JSON column addressed as
 * `meta.total` is not a relation and must not become `with('meta')`.
 *
 * This never replaces `$with`. A relation read by a `formatUsing()` closure,
 * by `recordTitle()`, or by a policy is invisible to any derivation — there is
 * no name to read it from. Declaring those stays the developer's job.
 */
final class EagerLoadPaths
{
    /**
     * @param  list<string>  $names  attribute names, dotted or not
     * @return list<string> relation paths, deduplicated, in first-seen order
     */
    public static function from(Model $model, array $names): array
    {
        $paths = [];

        foreach ($names as $name) {
            $path = self::pathFor($model, $name);

            if ($path !== null && ! in_array($path, $paths, true)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * The relation chain a dotted name walks, or null when it walks none.
     *
     * The last segment is the attribute being read, so it is dropped: only
     * what comes before it is a relation to load.
     */
    private static function pathFor(Model $model, string $name): ?string
    {
        if (! str_contains($name, '.')) {
            return null;
        }

        $segments = explode('.', $name);

        array_pop($segments);

        $current = $model;
        $path = [];

        foreach ($segments as $segment) {
            $related = self::relatedFor($current, $segment);

            if ($related === null) {
                return null;
            }

            $path[] = $segment;
            $current = $related;
        }

        return $path === [] ? null : implode('.', $path);
    }

    /**
     * The model on the far side of one relation, or null if it is not one.
     *
     * `isRelation()` answers on `method_exists`, which is broad enough to
     * accept a method that is not a relation at all and one that needs
     * arguments. Both would raise while merely *guessing* at an optimisation,
     * so the call is guarded and the result is type-checked.
     */
    private static function relatedFor(Model $model, string $segment): ?Model
    {
        if ($segment === '' || ! $model->isRelation($segment)) {
            return null;
        }

        try {
            $relation = $model->{$segment}();
        } catch (Throwable) {
            return null;
        }

        return $relation instanceof Relation ? $relation->getRelated() : null;
    }
}
