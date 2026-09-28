<?php

declare(strict_types=1);

namespace PandaPanel\Support;

use Illuminate\Support\Facades\Lang;

/**
 * The one place a name becomes a word somebody reads.
 *
 * A column named `created_at` renders as "Created At", a resource for a
 * `User` model as "User", an action named `force-delete` as "Force Delete".
 * `Str::headline()` produces all three, and it produces them in English —
 * which made the package's *own* translations only half the job. Every label
 * the panel derived from your code stayed English however the locale was set,
 * and the only fix was `->label()` on every column of every table.
 *
 * So the derivation asks first. Before falling back to `Str::headline()` it
 * looks for a translation the *application* owns, keyed by the name it was
 * about to headline:
 *
 *     // lang/id/panel.php
 *     return [
 *         'fields' => [
 *             'created_at' => 'Dibuat pada',
 *             'name' => 'Nama',
 *         ],
 *         'resources' => ['User' => 'Pengguna'],
 *     ];
 *
 * One file, and every column, field, entry, filter and export column named
 * `created_at` anywhere in the panel follows it.
 *
 * The file belongs to the application and not to this package, because these
 * are the application's words: `created_at` is its column and `User` is its
 * model. The package translates only what the package wrote — see
 * `lang/en` and `lang/id` beside this directory.
 *
 * Nothing here is required. An application with no such file gets exactly the
 * behaviour it had before: `->label()` where it was set, `Str::headline()`
 * everywhere else.
 *
 * ## Scope
 *
 * The lookup is flat: `fields.name` is one answer for every table in every
 * panel. Two resources that need different words for the same attribute name
 * say so the way they always could — `->label()` on the one that differs,
 * which is checked before this is ever reached.
 */
final class Label
{
    /**
     * The application file the lookup reads, without a locale or extension.
     */
    private const DEFAULT_FILE = 'panel';

    /**
     * What the application calls this, or null if it has not said.
     *
     * Null rather than the fallback, so a caller that has to do something
     * other than headline the name — pluralize it, strip a suffix — can tell
     * "translated" from "not translated" and skip the English inflection that
     * would otherwise be applied to an Indonesian word.
     */
    public static function lookup(string $group, string $name): ?string
    {
        if ($name === '') {
            return null;
        }

        // The group as a whole, then an exact key inside it — rather than
        // asking for `panel.fields.user.name` directly. A relation attribute
        // *is* named `user.name`, and Laravel's dot lookup would read that as
        // a `user` array containing `name`, which an application cannot write
        // while it also has a plain field called `user`. Reading the group
        // and indexing it makes the key exactly the name it labels.
        $entries = Lang::get(self::file().'.'.$group);

        if (is_array($entries) && is_string($entries[$name] ?? null)) {
            return $entries[$name];
        }

        // A nested form still works, for an application that prefers to write
        // `'user' => ['name' => …]` and has no plain `user` field to clash
        // with. `Lang::has()` rather than comparing `Lang::get()` against the
        // key it was passed: a translation whose value equals its own key is
        // legal.
        $key = self::file().'.'.$group.'.'.$name;

        if (! str_contains($name, '.') || ! Lang::has($key)) {
            return null;
        }

        $translation = Lang::get($key);

        return is_string($translation) ? $translation : null;
    }

    /**
     * The application's word, or the derivation it falls back to.
     *
     * @param  \Closure(): string  $fallback  Evaluated only when nothing is translated.
     */
    public static function resolve(string $group, string $name, \Closure $fallback): string
    {
        return self::lookup($group, $name) ?? $fallback();
    }

    /**
     * Which file the lookup reads, from `panda-panel.labels.file`.
     *
     * Configurable because `panel` is a plausible name for a file an
     * application already has, and a package that silently shared it would be
     * reading somebody else's array.
     */
    private static function file(): string
    {
        $file = config('panda-panel.labels.file', self::DEFAULT_FILE);

        return is_string($file) && $file !== '' ? $file : self::DEFAULT_FILE;
    }
}
