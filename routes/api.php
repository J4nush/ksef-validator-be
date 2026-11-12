<?php

declare(strict_types=1);

use App\Http\Controllers\ValidateInvoiceController;
use App\Http\Middleware\ReadInvoiceBody;
use App\Http\Middleware\RequireXmlContentType;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

Route::post('internal/validate', ValidateInvoiceController::class)
    ->middleware(['throttle:validate', RequireXmlContentType::class, ReadInvoiceBody::class]);

// The router would answer OPTIONS with 200 and list OPTIONS in Allow.
Route::match(['GET', 'HEAD', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], 'internal/validate', fn () => throw new MethodNotAllowedHttpException(['POST']));
