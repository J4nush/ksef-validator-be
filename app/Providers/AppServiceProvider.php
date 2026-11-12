<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\ClientKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use KsefFa3\Fa3Validator;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Fa3Validator::class);
    }

    public function boot(): void
    {
        RateLimiter::for('validate', function (Request $request): array {
            $client = ClientKey::for((string) $request->ip());

            return [
                Limit::perMinute(config()->integer('ksef.rate_limit.per_minute'))->by('minute:'.$client),
                Limit::perDay(config()->integer('ksef.rate_limit.per_day'))->by('day:'.$client),
            ];
        });
    }
}
