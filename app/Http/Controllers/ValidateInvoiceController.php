<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\ReadInvoiceBody;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use KsefFa3\Fa3Validator;

final class ValidateInvoiceController
{
    public function __invoke(Request $request, Fa3Validator $validator): Response
    {
        $report = $validator->validate(ReadInvoiceBody::bytesOf($request));

        return new Response($report->toJson(), 200, ['Content-Type' => 'application/json']);
    }
}
