<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

final class RequireXmlContentType
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $mediaType = Str::lower(trim(Str::before((string) $request->headers->get('Content-Type'), ';')));

        if ($mediaType !== 'application/xml' && $mediaType !== 'text/xml') {
            throw new UnsupportedMediaTypeHttpException;
        }

        return $next($request);
    }
}
