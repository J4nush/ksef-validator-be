<?php

declare(strict_types=1);

return [
    'default' => 'array',
    'limiter' => 'apc',
    'stores' => [
        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],
        'apc' => [
            'driver' => 'apc',
        ],
    ],
    'prefix' => env('CACHE_PREFIX', 'ksef-validator-'),
    'serializable_classes' => false,
];
