<?php

declare(strict_types=1);

return [
    'rate_limit' => [
        'per_minute' => (int) env('RATE_LIMIT_PER_MINUTE', 30),
        'per_day' => (int) env('RATE_LIMIT_PER_DAY', 1000),
    ],
];
