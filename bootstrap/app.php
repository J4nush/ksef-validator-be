<?php

declare(strict_types=1);

use App\Exceptions\SafeExceptionReport;
use App\Http\Middleware\SecurityHeaders;
use App\Http\ProblemResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(api: __DIR__.'/../routes/api.php')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(SecurityHeaders::class);
        $middleware->remove(HandleCors::class);
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(SafeExceptionReport::log(...));
        $exceptions->render(fn (Throwable $exception) => ProblemResponse::from($exception));
    })
    ->create();
