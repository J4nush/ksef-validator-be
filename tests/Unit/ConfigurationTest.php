<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use KsefFa3\Fa3Validator;

it('keeps no session, database or cookies', function () {
    expect(config('session.driver'))->toBe('array')
        ->and(config('cache.default'))->toBe('array')
        ->and(is_dir(base_path('database')))->toBeFalse()
        ->and(is_file(base_path('routes/web.php')))->toBeFalse();
});

it('rate limits from APCu shared by all workers', function () {
    expect(config('cache.limiter'))->toBe('apc')
        ->and(config('cache.stores.apc.driver'))->toBe('apc')
        ->and(config('ksef.rate_limit.per_minute'))->toBe(30)
        ->and(config('ksef.rate_limit.per_day'))->toBe(1000);
});

it('logs to stderr only', function () {
    expect(config('logging.default'))->toBe('stderr')
        ->and(config('logging.channels.stderr.handler_with.stream'))->toBe('php://stderr')
        ->and(config('logging.deprecations.channel'))->toBe('null');
});

it('trusts no proxy unless configured', function () {
    expect(config('trustedproxy.proxies'))->toBe([]);
});

it('reads trusted proxies as a list of CIDR ranges', function () {
    $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = '172.18.0.0/16, 10.0.0.1';

    try {
        /** @var array{proxies: list<string>} $config */
        $config = require base_path('config/trustedproxy.php');
    } finally {
        $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = '';
    }

    expect($config['proxies'])->toBe(['172.18.0.0/16', '10.0.0.1']);
});

it('loads the engine once per worker and limits request time', function () {
    expect(config('octane.warm'))->toContain(Fa3Validator::class)
        ->and(config('octane.max_execution_time'))->toBe(10)
        ->and(config('octane.state_file'))->not->toContain('/logs/');
});

it('exposes only the validation endpoint', function () {
    $uris = array_values(array_unique(array_map(
        fn (Route $route): string => $route->uri(),
        app('router')->getRoutes()->getRoutes(),
    )));

    expect($uris)->toBe(['api/internal/validate']);
});
