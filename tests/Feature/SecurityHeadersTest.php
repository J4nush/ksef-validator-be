<?php

declare(strict_types=1);

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Testing\TestResponse;

covers(SecurityHeaders::class);

dataset('responses', [
    '200' => fn () => postInvoice(invoiceFixture('valid-minimal.xml')),
    '404' => fn () => http()->call('GET', '/api/unknown'),
    '405' => fn () => http()->call('GET', VALIDATE_URI),
    '413' => fn () => postInvoice('', server: ['CONTENT_LENGTH' => '3000001']),
    '415' => fn () => postInvoice('', 'text/plain'),
    '500' => function () {
        failingEngine();

        return postInvoice(invoiceFixture('valid-minimal.xml'));
    },
]);

it('sends the security headers on every response', function (TestResponse $response) {
    expect($response->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; frame-ancestors 'none'")
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->get('Cross-Origin-Resource-Policy'))->toBe('same-origin');
})->with('responses');

it('sets no cookies and does not advertise the stack', function (TestResponse $response) {
    expect($response->headers->getCookies())->toBe([])
        ->and($response->headers->has('Set-Cookie'))->toBeFalse()
        ->and($response->headers->has('X-Powered-By'))->toBeFalse();
})->with('responses');

it('sends the headers on 429 too', function () {
    config(['ksef.rate_limit.per_minute' => 1]);
    postInvoice('');

    $response = postInvoice('');

    expect($response->getStatusCode())->toBe(429)
        ->and($response->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; frame-ancestors 'none'")
        ->and($response->headers->has('Set-Cookie'))->toBeFalse();
});

it('does not answer cross-origin requests with CORS headers', function () {
    $response = postInvoice(invoiceFixture('valid-minimal.xml'), server: ['HTTP_ORIGIN' => 'https://example.com']);
    $preflight = http()->call('OPTIONS', VALIDATE_URI, server: [
        'HTTP_ORIGIN' => 'https://example.com',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ]);

    expect($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse()
        ->and($preflight->headers->has('Access-Control-Allow-Origin'))->toBeFalse()
        ->and($preflight->getStatusCode())->toBe(405);
});
