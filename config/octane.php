<?php

declare(strict_types=1);

use KsefFa3\Fa3Validator;
use Laravel\Octane\Octane;

return [
    'server' => 'frankenphp',
    'https' => false,
    'warm' => [
        ...Octane::defaultServicesToWarm(),
        Fa3Validator::class,
    ],
    'max_execution_time' => 10,
    'state_file' => storage_path('framework/octane-server-state.json'),
];
