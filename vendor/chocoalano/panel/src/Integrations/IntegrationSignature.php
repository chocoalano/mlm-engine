<?php

declare(strict_types=1);

namespace PandaPanel\Integrations;

/**
 * Proof that a request came from this panel.
 *
 * A webhook receiver has no other way to know. The URL is often guessable, and
 * an endpoint that acts on whatever is posted to it is an endpoint anybody can
 * drive — so every request carries an HMAC over its own body, and a receiver
 * that checks it knows both who sent the request and that nothing in it was
 * altered on the way.
 *
 * The scheme is the one Stripe uses, because it is well understood and because
 * receivers frequently already have code for it:
 *
 *     X-Panel-Signature: t=1755250000,v1=9f86d0818...
 *     X-Panel-Delivery:  0f9c1a1e-...
 *
 * The timestamp is inside the signed string, not merely beside it. Signing the
 * body alone would produce a signature that stays valid forever, and a
 * recorded request could then be replayed at any time by anyone who saw it
 * once. With the timestamp signed, a receiver rejects anything older than its
 * own tolerance and a replay has minutes rather than years.
 *
 * `X-Panel-Delivery` is a uuid, stable across the retries of one delivery, so
 * a receiver can make its own handling idempotent — which matters because
 * `after` triggers are queued and retried.
 */
final class IntegrationSignature
{
    public const SIGNATURE_HEADER = 'X-Panel-Signature';

    public const DELIVERY_HEADER = 'X-Panel-Delivery';

    /**
     * The signed string. Documented here because a receiver has to rebuild it
     * exactly, and "the body" is ambiguous about the timestamp.
     */
    public static function payload(int $timestamp, string $body): string
    {
        return $timestamp.'.'.$body;
    }

    public static function sign(string $secret, int $timestamp, string $body): string
    {
        return hash_hmac('sha256', self::payload($timestamp, $body), $secret);
    }

    public static function header(string $secret, int $timestamp, string $body): string
    {
        return sprintf('t=%d,v1=%s', $timestamp, self::sign($secret, $timestamp, $body));
    }

    /**
     * Whether a header a receiver was sent is genuine.
     *
     * Shipped as part of the package rather than only documented, because a
     * Laravel application receiving its own panel's webhooks should not have
     * to write this — and because `hash_equals` is the detail most hand-rolled
     * verifications get wrong. A `===` on a hex string leaks how much of the
     * signature was correct through how long the comparison took.
     *
     * @param  int  $tolerance  seconds a timestamp may be out by
     */
    public static function verify(
        string $header,
        string $secret,
        string $body,
        int $tolerance = 300,
        ?int $now = null,
    ): bool {
        $parts = [];

        foreach (explode(',', $header) as $piece) {
            $pair = explode('=', trim($piece), 2);

            if (count($pair) === 2) {
                $parts[$pair[0]] = $pair[1];
            }
        }

        $timestamp = isset($parts['t']) && ctype_digit($parts['t']) ? (int) $parts['t'] : null;
        $signature = $parts['v1'] ?? null;

        if ($timestamp === null || ! is_string($signature)) {
            return false;
        }

        $now ??= time();

        if ($tolerance > 0 && abs($now - $timestamp) > $tolerance) {
            return false;
        }

        return hash_equals(self::sign($secret, $timestamp, $body), $signature);
    }

    /**
     * A fresh secret, for an integration that has none.
     *
     * 32 bytes of `random_bytes` as hex. Generated rather than asked for: a
     * secret somebody has to invent is a secret that ends up being the name of
     * the project.
     */
    public static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }
}
