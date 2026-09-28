<?php

declare(strict_types=1);

namespace PandaPanel\Support;

use Illuminate\Support\Facades\File;

/**
 * Whether the published frontend speaks the same protocol as this backend.
 *
 * The panel's Vue components are published into the application rather than
 * imported from the package — the component registries are build-time
 * `import.meta.glob` allowlists over the application's own tree, and a
 * component you cannot read the source of is one you cannot debug. The cost
 * is that after a `composer update` the PHP is new and the components are
 * whatever was published last.
 *
 * `AssetManifest` already reports *which files* are behind. It cannot say
 * whether being behind matters, because most of the time it does not: a
 * reworded label drifting for a release is invisible. What is not invisible,
 * and what nobody diagnosed from the symptom, is the frontend that is behind
 * in a way that changed the wire:
 *
 * - the backend sends a prop the old component does not read, so a feature is
 *   simply absent and nothing is logged;
 * - the backend adds an endpoint the old component never calls, so a dialog
 *   opens empty or a button does nothing;
 * - a payload changes shape, and the narrowing in the component rejects it and
 *   leaves the previous state on screen — by design, and indistinguishable
 *   from "the server did not answer".
 *
 * All three read as "the panel is broken" with nothing in any log. The
 * developer finds out from a screenshot.
 *
 * ## The fingerprint
 *
 * One integer, bumped whenever the payload contract between this package's PHP
 * and its Vue changes. Not a hash of the source: the source changes constantly
 * and almost none of it is contractual, so a hash would cry wolf every release
 * and be ignored by the second one. Not the package version either, for the
 * same reason — most releases change nothing a component can see.
 *
 * The number lives in two files that are published together: this constant,
 * and `resources/js/panel/contract.ts`. The published copy carries whichever
 * number it was published at, so a stale frontend reports an old one and the
 * mismatch is the whole detection.
 *
 * ## What happens on a mismatch
 *
 * The expected version travels with the shell props; the frontend compares it
 * against the one compiled into it and says so in the console, in development
 * only. `panel:assets` says it too, with the command that fixes it. Neither
 * degrades anything: a mismatch is a warning about why something *else* is
 * behaving oddly, and turning it into a failure would replace a subtle problem
 * with a total one.
 */
final class FrontendContract
{
    /**
     * The protocol this backend speaks.
     *
     * Bump when — and only when — the PHP starts sending something the
     * published Vue must be new enough to understand.
     *
     * 1. The contract as it stood before this was tracked.
     * 2. Relation actions carry forms (`relations/action-form`); action and
     *    relation forms carry `optionsUrl` and `formStateUrl`; the form-state
     *    response carries `statePatch`; a select declares `dependentOptions`
     *    and its options endpoint accepts POSTed state; create and edit pages
     *    send `submitLabel`; tenancy carries `label`.
     */
    public const VERSION = 2;

    /**
     * Where the published constant lives, relative to the panel's own tree.
     */
    private const FILE = 'contract.ts';

    public static function expected(): int
    {
        return self::VERSION;
    }

    /**
     * The version the application's published frontend declares.
     *
     * Null when there is nothing published — an application reading the
     * package's own components is current by definition and has no drift to
     * report. Also null when the file is there but says nothing recognisable,
     * which is the honest answer: "cannot tell" is not "mismatched", and
     * warning about a file this cannot parse would be noise.
     */
    public static function published(): ?int
    {
        $path = FrontendPaths::panel(self::FILE);

        if (! File::exists($path)) {
            return null;
        }

        $matched = preg_match(
            '/PANEL_CONTRACT_VERSION\s*(?::\s*number\s*)?=\s*(\d+)/',
            (string) File::get($path),
            $matches,
        );

        return $matched === 1 ? (int) $matches[1] : null;
    }

    /**
     * Whether the published frontend is behind the backend.
     *
     * Only behind. A frontend *ahead* of the backend happens while somebody is
     * developing the package itself and is not a state an application can get
     * into by updating, so warning about it would only ever be wrong.
     */
    public static function isDrifted(): bool
    {
        $published = self::published();

        return $published !== null && $published < self::VERSION;
    }

    /**
     * What to run about it.
     */
    public static function remediation(): string
    {
        return 'php artisan panel:assets --update';
    }
}
