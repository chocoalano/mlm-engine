<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Panda MLM — technical package configuration
|--------------------------------------------------------------------------
|
| Infrastructure only: where the package stores, queues and caches. Business
| plan rules (pairing ratios, matrix width, commission percentages, rank
| requirements) never belong here — they are versioned per plan in the
| database, so two tenants can run two different plans.
|
| A null connection or store means the application's default.
|
*/

return [

    'database' => [
        'connection' => env('MLM_DB_CONNECTION'),
    ],

    'queue' => [
        'connection' => env('MLM_QUEUE_CONNECTION'),
        'name' => env('MLM_QUEUE_NAME', 'mlm'),
    ],

    'cache' => [
        'store' => env('MLM_CACHE_STORE'),
        'prefix' => env('MLM_CACHE_PREFIX', 'mlm'),
    ],

];
