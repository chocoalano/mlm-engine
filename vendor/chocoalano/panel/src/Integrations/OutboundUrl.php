<?php

declare(strict_types=1);

namespace PandaPanel\Integrations;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * Whether the server is allowed to send a request somewhere.
 *
 * This screen lets a panel user type a URL that the *server* then requests.
 * That is server-side request forgery by construction, and it is not a
 * hypothetical: `http://169.254.169.254/latest/meta-data/iam/security-
 * credentials/` is one text field away from handing an admin the machine's
 * cloud credentials, and `http://localhost:6379` reaches a Redis that is
 * bound to loopback precisely because it was assumed unreachable.
 *
 * Two independent gates, and a URL has to pass both:
 *
 * 1. **The host is on the allowlist**, which is empty by default. Deny by
 *    default rather than allow: a panel installed and never configured can
 *    reach nothing at all, and adding a destination is a deploy rather than a
 *    form submission.
 * 2. **The address is public.** Checked after the allowlist and again at
 *    request time, because a name on the allowlist can still resolve into the
 *    private range — by mistake, or by a DNS record whose owner changed it
 *    after it was approved.
 *
 * The second check is what makes the first safe to relax. An operator who
 * turns the allowlist off still cannot reach their own network.
 */
final class OutboundUrl
{
    /**
     * Ranges no integration may reach.
     *
     * `169.254.0.0/16` is the one that matters most: every major cloud puts
     * an unauthenticated credential endpoint on `169.254.169.254`. The rest
     * are the private and loopback ranges, plus their IPv6 equivalents.
     *
     * @var list<string>
     */
    private const BLOCKED_RANGES = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '100.64.0.0/10',
        '::1/128',
        'fc00::/7',
        'fe80::/10',
    ];

    /**
     * Why this URL is refused, or null when it is allowed.
     *
     * A sentence rather than a boolean: it is shown to whoever typed the URL,
     * and "that host is not on the allowlist" plus the config key to add it to
     * is the difference between a fixable refusal and a mystery.
     */
    public static function rejection(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'])) {
            return __('panda-panel::errors.outbound_url.not_a_url');
        }

        // The scheme is checked before the host, because `file:///etc/passwd`
        // has no host and "that is not a URL" would be the wrong thing to say
        // about it.
        $scheme = mb_strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return __('panda-panel::errors.outbound_url.unsupported_scheme', ['scheme' => $scheme]);
        }

        if (! isset($parts['host'])) {
            return __('panda-panel::errors.outbound_url.not_a_url');
        }

        $host = $parts['host'];

        if (! self::isAllowedHost($host)) {
            return sprintf(
                '%s is not an allowed destination. Add it to integrations.allowed_hosts '
                    .'in config/panda-panel.php.',
                $host,
            );
        }

        $addressRejection = self::addressRejection($host);

        if ($addressRejection !== null) {
            return $addressRejection;
        }

        return null;
    }

    public static function isAllowed(string $url): bool
    {
        return self::rejection($url) === null;
    }

    /**
     * Patterns are matched with `Str::is()`, so `*.partner.io` covers a
     * subdomain without naming each one — and, deliberately, does not cover
     * `partner.io.attacker.test`, because the pattern is anchored at both
     * ends.
     */
    private static function isAllowedHost(string $host): bool
    {
        /** @var list<string> $allowed */
        $allowed = array_values(array_filter(
            (array) Config::get('panda-panel.integrations.allowed_hosts', []),
            static fn (mixed $pattern): bool => is_string($pattern) && $pattern !== '',
        ));

        if ($allowed === []) {
            return false;
        }

        $host = mb_strtolower($host);

        foreach ($allowed as $pattern) {
            if (Str::is(mb_strtolower($pattern), $host)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any address this host resolves to is one we refuse to reach.
     *
     * Every address, not the first: a name that answers with one public
     * address and one loopback address is the classic way past a check that
     * only looks at one of them.
     */
    private static function addressRejection(string $host): ?string
    {
        if (Config::get('panda-panel.integrations.block_private_networks', true) !== true) {
            return null;
        }

        $addresses = self::addressesFor($host);

        if ($addresses === []) {
            return sprintf(
                '%s could not be resolved to a public address from this server.',
                $host,
            );
        }

        foreach ($addresses as $address) {
            if (self::isBlocked($address)) {
                return sprintf(
                    '%s resolves to a private or link-local address, which an integration may '
                        .'not reach.',
                    $host,
                );
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function addressesFor(string $host): array
    {
        // A literal address needs no lookup, and is the case worth getting
        // right: `http://127.0.0.1` never reaches a resolver at all.
        //
        // `parse_url()` keeps the brackets an IPv6 literal wears in a URL, and
        // `[::1]` is not an address as far as `filter_var()` is concerned — so
        // stripping them is what stops `http://[::1]:8080` from being treated
        // as an unresolvable name and waved through.
        $literal = trim($host, '[]');

        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return [$literal];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        $addresses = [];

        if ($records !== false) {
            foreach ($records as $record) {
                foreach (['ip', 'ipv6'] as $key) {
                    if (is_string($record[$key] ?? null)) {
                        $addresses[] = $record[$key];
                    }
                }
            }
        }

        $ipv4 = @gethostbynamel($host);

        if ($ipv4 !== false) {
            $addresses = [...$addresses, ...$ipv4];
        }

        return array_values(array_unique($addresses));
    }

    private static function isBlocked(string $address): bool
    {
        $mappedIpv4 = self::mappedIpv4($address);

        if ($mappedIpv4 !== null) {
            return self::isBlocked($mappedIpv4);
        }

        foreach (self::BLOCKED_RANGES as $range) {
            if (self::inRange($address, $range)) {
                return true;
            }
        }

        return false;
    }

    private static function mappedIpv4(string $address): ?string
    {
        $binary = inet_pton($address);

        if ($binary === false || strlen($binary) !== 16) {
            return null;
        }

        if (substr($binary, 0, 12) !== str_repeat("\0", 10)."\xff\xff") {
            return null;
        }

        $ipv4 = inet_ntop(substr($binary, 12));

        return $ipv4 === false ? null : $ipv4;
    }

    private static function inRange(string $address, string $range): bool
    {
        [$subnet, $bits] = explode('/', $range);

        $binaryAddress = inet_pton($address);
        $binarySubnet = inet_pton($subnet);

        // Different families never overlap, so an IPv4 address is never in an
        // IPv6 range and the comparison below would be meaningless.
        if ($binaryAddress === false
            || $binarySubnet === false
            || strlen($binaryAddress) !== strlen($binarySubnet)) {
            return false;
        }

        $bits = (int) $bits;

        $wholeBytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($wholeBytes > 0 && strncmp($binaryAddress, $binarySubnet, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainder)) - 1) & 0xFF;

        return (ord($binaryAddress[$wholeBytes]) & $mask)
            === (ord($binarySubnet[$wholeBytes]) & $mask);
    }
}
