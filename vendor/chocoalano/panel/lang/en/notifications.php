<?php

declare(strict_types=1);

/*
 * What the panel says when something happened that the user did not watch
 * happen: a request that failed under them, or an email sent to finish a
 * sign-in.
 *
 * `http` is keyed by status because that is what the frontend receives. A
 * status absent from it keeps Inertia's own error handling, so adding a key
 * here is how a panel starts speaking about a status it used to stay quiet
 * about.
 */

return [
    'http' => [
        403 => [
            'title' => 'Not allowed',
            'body' => 'You do not have permission to do that.',
        ],
        404 => [
            'title' => 'Not found',
            'body' => 'That record no longer exists.',
        ],
        419 => [
            'title' => 'Session expired',
            'body' => 'Refresh the page and try again.',
        ],
        429 => [
            'title' => 'Too many requests',
            'body' => 'Wait a moment and try again.',
        ],
        500 => [
            'title' => 'Something went wrong',
            'body' => 'The request could not be completed.',
        ],
        503 => [
            'title' => 'Temporarily unavailable',
            'body' => 'The application is down for maintenance.',
        ],
    ],

    'two_factor_code' => [
        'subject' => 'Your sign-in code',
        'intro' => 'Use this code to finish signing in:',
        'expiry' => 'It expires in ten minutes and can be used once.',
        'warning' => 'If you did not try to sign in, change your password — somebody else knows it.',
    ],

    'two_factor' => [
        'throttled' => 'Too many codes requested. Try again later.',
        'sent' => 'A new code is on its way.',
        'invalid' => 'That code is wrong or has expired.',
        'enabled' => 'Email codes are on.',
        'disabled' => 'Email codes are off.',
    ],

    'two_factor_required' => 'Set up two-factor authentication to continue.',
];
