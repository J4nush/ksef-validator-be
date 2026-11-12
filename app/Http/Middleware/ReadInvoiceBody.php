<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use KsefFa3\Stage\PreflightStage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ReadInvoiceBody
{
    private const string ATTRIBUTE = 'invoice.bytes';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $declared = $request->headers->get('Content-Length');

        if (is_numeric($declared) && $declared > PreflightStage::MAX_BYTES) {
            throw self::tooLarge();
        }

        $bytes = (string) stream_get_contents($request->getContent(true), PreflightStage::MAX_BYTES + 1);

        if (strlen($bytes) > PreflightStage::MAX_BYTES) {
            throw self::tooLarge();
        }

        $request->attributes->set(self::ATTRIBUTE, $bytes);

        return $next($request);
    }

    public static function bytesOf(Request $request): string
    {
        return $request->attributes->getString(self::ATTRIBUTE);
    }

    private static function tooLarge(): HttpException
    {
        return new HttpException(Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
    }
}
