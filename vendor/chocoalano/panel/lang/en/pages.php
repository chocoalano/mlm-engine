<?php

declare(strict_types=1);

/*
 * The screens the package registers into every panel by itself, and the
 * strings a widget shows before it has anything to show.
 *
 * A panel's own pages are not here: their titles come from the application,
 * which is where their translations belong too.
 */

return [
    'navigation_group' => [
        'account' => 'Account',
    ],

    'dashboard' => [
        'title' => 'Dashboard',
    ],

    'profile' => [
        'title' => 'Profile',
        'subheading' => 'Update your name and email address.',
    ],

    'security' => [
        'title' => 'Security',
        'subheading' => 'Password, two-factor authentication, and passkeys.',
    ],

    'appearance' => [
        'title' => 'Appearance',
        'subheading' => 'Choose how the interface looks on this device.',
    ],

    'record_navigation' => [
        'view' => 'View',
        'edit' => 'Edit',
    ],

    'widgets' => [
        'table_empty' => 'Nothing to show yet.',
    ],
];
