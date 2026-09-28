<?php

declare(strict_types=1);

namespace PandaPanel\Integrations;

use Illuminate\Support\Arr;

/**
 * `{{ record.email }}` in a hand-written body.
 *
 * Deliberately not Blade. A body typed into a form is untrusted input, and
 * compiling untrusted input as Blade is remote code execution with extra
 * steps. This resolves dotted paths against the payload array and does
 * nothing else — no conditionals, no loops, no function calls, nothing that
 * could reach outside the array it was given.
 *
 * A path that is not in the payload renders as an empty string rather than
 * leaving the placeholder in place, so a receiving system gets a blank field
 * instead of the literal text `{{ record.nope }}`.
 */
final class IntegrationTemplate
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function render(string $template, array $payload): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([A-Za-z0-9_.\-]+)\s*\}\}/',
            static function (array $matches) use ($payload): string {
                $value = Arr::get($payload, $matches[1]);

                return match (true) {
                    $value === null => '',
                    is_bool($value) => $value ? 'true' : 'false',
                    is_scalar($value) => (string) $value,
                    // An array becomes the JSON it is, which is what a body
                    // interpolating a whole relation is asking for.
                    default => (string) json_encode($value),
                };
            },
            $template,
        );
    }
}
