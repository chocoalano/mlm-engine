<?php

declare(strict_types=1);

namespace PandaPanel\Support;

/**
 * A panel's colours, as CSS custom properties.
 *
 * Not an enum, and deliberately unlike `BadgeColor`. Those are *semantic* —
 * success, danger, warning — a closed set because each maps to a literal
 * Tailwind class the build has to have seen. These are the theme: the values
 * behind `--primary`, `--background`, and the rest, which shadcn already
 * reads as custom properties. A custom property is set at runtime by
 * definition, so a panel can carry its own without anything being compiled.
 *
 * That split is the whole design. A colour that names a *meaning* is closed,
 * because meanings are finite and each needs a class. A colour that names a
 * *value* is open, because it never becomes a class.
 *
 * Values are validated before they cross. These end up inside a `style`
 * attribute, so a string that is a stylesheet rather than a colour would be
 * rendered as one — the same reason `ColorEntry` validates.
 */
final class PanelTheme
{
    /**
     * The properties a panel may set.
     *
     * An allowlist rather than anything: a typo would otherwise be a custom
     * property nothing reads, which is a theme that silently does not apply.
     * Every name here is one the stylesheet actually consumes.
     *
     * @var list<string>
     */
    private const PROPERTIES = [
        'primary',
        'primary-foreground',
        'secondary',
        'secondary-foreground',
        'accent',
        'accent-foreground',
        'background',
        'foreground',
        'muted',
        'muted-foreground',
        'destructive',
        'border',
        'ring',
        // `sidebar` and `sidebar-background` name the same surface. The
        // stylesheet resolves `bg-sidebar` from `--sidebar-background`, which
        // is the canonical one; `sidebar` is what applications already write
        // and is aliased onto it by the frontend, so both arrive.
        'sidebar',
        'sidebar-background',
        'sidebar-foreground',
        'sidebar-primary',
        'sidebar-accent',
        'sidebar-border',
    ];

    /**
     * What a colour may look like.
     *
     * Hex, `rgb()`, `hsl()`, and `oklch()` — the last because that is what
     * this application's own stylesheet is written in.
     */
    private const COLOR = '/^(#[0-9a-f]{3,8}|(rgb|hsl|oklch)a?\([^();]*\))$/i';

    /** @var array<string, string> */
    private array $light = [];

    /** @var array<string, string> */
    private array $dark = [];

    /**
     * @param  array<string, string>  $colors  keyed by property name, without the `--`
     */
    public function light(array $colors): self
    {
        $this->light = [...$this->light, ...self::sanitize($colors)];

        return $this;
    }

    /**
     * @param  array<string, string>  $colors
     */
    public function dark(array $colors): self
    {
        $this->dark = [...$this->dark, ...self::sanitize($colors)];

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->light === [] && $this->dark === [];
    }

    /**
     * @return array{light: array<string, string>, dark: array<string, string>}
     */
    public function toArray(): array
    {
        return ['light' => $this->light, 'dark' => $this->dark];
    }

    /**
     * Keeps only properties the stylesheet reads, holding only values that
     * are colours.
     *
     * Dropped rather than refused: a panel with one bad colour should render
     * with the rest of its theme, not fail to render at all.
     *
     * @param  array<string, string>  $colors
     * @return array<string, string>
     */
    private static function sanitize(array $colors): array
    {
        $clean = [];

        foreach ($colors as $property => $value) {
            $property = ltrim((string) $property, '-');

            if (! in_array($property, self::PROPERTIES, true)) {
                continue;
            }

            if (preg_match(self::COLOR, trim($value)) !== 1) {
                continue;
            }

            $clean[$property] = trim($value);
        }

        return $clean;
    }
}
