<?php

declare(strict_types=1);

/*
 * The table's own chrome — everything a table says that no column, filter or
 * action put there. Each of these is a default: a table that calls
 * `->emptyState()` or `->searchPlaceholder()` overrides it, and this file is
 * only what it says before anybody does.
 */

return [
    'reordered' => 'Order updated.',

    'empty_state' => [
        'heading' => 'No records found',
    ],

    'search' => [
        'placeholder' => 'Search...',
    ],

    'filters' => [
        'trigger' => 'Filters',
        'apply' => 'Apply filters',
        'reset' => 'Clear',
    ],

    'trashed_filter' => [
        'label' => 'Deleted records',
        'without' => 'Hidden',
        'with' => 'Included',
        'only' => 'Only deleted',
    ],
];
