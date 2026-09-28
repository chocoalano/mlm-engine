<?php

declare(strict_types=1);

/*
 * The points in a record's lifecycle an integration can fire at. They are
 * shown in a select, so the wording is what somebody picks from rather than
 * the enum's own value.
 */

return [
    'page' => [
        'title' => ':resource integrations',
        'heading' => 'Integrations',
        'subheading' => 'Requests this panel sends when a :label is written.',
    ],

    'saved' => 'Integration saved.',
    'deleted' => 'Integration deleted.',
    'secret_rotated' => 'Signing secret replaced.',

    'trigger' => [
        'before_create' => 'Before create',
        'after_create' => 'After create',
        'before_update' => 'Before update',
        'after_update' => 'After update',
        'before_delete' => 'Before delete',
        'after_delete' => 'After delete',
    ],
];
