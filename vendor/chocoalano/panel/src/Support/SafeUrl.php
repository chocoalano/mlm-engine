<?php

declare(strict_types=1);

namespace PandaPanel\Support;

/**
 * URLs that are safe to hand to the browser as navigation targets.
 *
 * The panel mostly emits its own relative routes, but schemas may also attach
 * links to actions, cells, stats and notifications. Those links can come from
 * application data, so they get the same scheme check everywhere before they
 * become an href or a window.location assignment.
 */
final class SafeUrl
{
    /** @var list<string> */
    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public static function sanitize(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $trimmed = trim($url);

        if ($trimmed === '' || ! self::isAllowed($trimmed)) {
            return null;
        }

        return $trimmed;
    }

    public static function isAllowed(string $url): bool
    {
        $decoded = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $compact = preg_replace('/[\x00-\x20\x7F]+/u', '', $decoded) ?? '';

        if ($compact === '' || str_starts_with($compact, '//') || str_starts_with($compact, '\\')) {
            return false;
        }

        $scheme = parse_url($compact, PHP_URL_SCHEME);

        if (! is_string($scheme) || $scheme === '') {
            return true;
        }

        return in_array(mb_strtolower($scheme), self::ALLOWED_SCHEMES, true);
    }
}
