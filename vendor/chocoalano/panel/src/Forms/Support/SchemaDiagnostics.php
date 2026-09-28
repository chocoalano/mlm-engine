<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Support;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use PandaPanel\Exceptions\PanelSchemaException;

/**
 * What to do about a schema that is self-contradictory.
 *
 * Two kinds of mistake are found while a form is compiled: a field that writes
 * where another field already writes, and a field that is required but cannot
 * be submitted. Both produce a form that looks entirely correct and behaves
 * inexplicably — a value that will not save, an unrelated edit that will not
 * go through — so both are worth saying out loud rather than leaving to be
 * discovered from the symptom.
 *
 * ## Why not simply throw
 *
 * Because these are found by *new* checks, and a schema written before they
 * existed has been in production for as long as it has been wrong. Throwing
 * everywhere would take an application that works imperfectly and stop it
 * from working at all, at deploy time, over a form nobody was complaining
 * about.
 *
 * So the default is: throw where a developer is looking (`local`, `testing`),
 * log where a user is (everywhere else). Development is where the message is
 * useful and where an exception is cheap; production is where the exception
 * costs more than the bug it is reporting.
 *
 * The check that predates this — two fields with the same *name* at the top
 * level — still throws everywhere, because that is what it has always done
 * and nothing is served by making an existing guarantee weaker.
 *
 * ## Overriding
 *
 * `panda-panel.forms.diagnostics` takes `throw`, `log`, or `ignore`. An
 * application that would rather find out loudly in production can say so, and
 * one migrating a large legacy schema can turn it down while it does.
 */
final class SchemaDiagnostics
{
    /**
     * Reports a contradiction found while compiling a schema.
     */
    public static function report(PanelSchemaException $exception): void
    {
        switch (self::mode()) {
            case 'ignore':
                return;

            case 'throw':
                throw $exception;
            default:
                // Through the logger rather than `trigger_error`, so it lands
                // wherever the application already sends its diagnostics
                // instead of in whatever the PHP error handler does today.
                Log::warning($exception->getMessage());
        }
    }

    /**
     * `throw`, `log`, or `ignore`.
     */
    public static function mode(): string
    {
        $configured = config('panda-panel.forms.diagnostics');

        if (in_array($configured, ['throw', 'log', 'ignore'], true)) {
            return $configured;
        }

        return App::environment(['local', 'testing']) ? 'throw' : 'log';
    }
}
