<?php

declare(strict_types=1);

use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [
    'default' => 'stderr',
    'deprecations' => [
        'channel' => 'null',
        'trace' => false,
    ],
    'channels' => [
        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'warning'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],
    ],
];
