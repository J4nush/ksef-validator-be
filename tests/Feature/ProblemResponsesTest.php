<?php

declare(strict_types=1);

use App\Http\Middleware\ReadInvoiceBody;
use App\Http\Middleware\RequireXmlContentType;
use App\Http\ProblemResponse;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

covers(ProblemResponse::class, RequireXmlContentType::class, ReadInvoiceBody::class);

/**
 * @param  TestResponse<Response>  $response
 */
function expectProblem(TestResponse $response, int $status, string $type): void
{
    expect($response->getStatusCode())->toBe($status)
        ->and($response->headers->get('Content-Type'))->toBe('application/problem+json')
        ->and($response->json('type'))->toBe($type)
        ->and($response->json('status'))->toBe($status)
        ->and(contractErrors($response))->toBe([]);
}

it('rejects a content type other than XML with 415', function (?string $contentType) {
    $response = postInvoice(markedInvoice(), $contentType);

    expectProblem($response, 415, '/api/problems/unsupported-media-type');
    expect($response->getContent())->not->toContain(LOG_MARKER);
})->with([
    'missing' => [null],
    'JSON' => ['application/json'],
    'form' => ['application/x-www-form-urlencoded'],
    'multipart' => ['multipart/form-data; boundary=x'],
    'XML suffix' => ['application/atom+xml'],
    'prefix only' => ['application/xmlx'],
    'plain text' => ['text/plain'],
]);

it('rejects a declared Content-Length above the limit before reading the body', function () {
    $response = postInvoice(invoiceFixture('valid-minimal.xml'), server: ['CONTENT_LENGTH' => '3000001']);

    expectProblem($response, 413, '/api/problems/payload-too-large');
});

it('rejects a body longer than the limit even without a matching Content-Length', function () {
    $bytes = str_repeat(' ', 3_000_001);

    $response = postInvoice($bytes, server: ['CONTENT_LENGTH' => '10']);

    expectProblem($response, 413, '/api/problems/payload-too-large');
});

it('rejects a body one byte over the limit', function () {
    $response = postInvoice(markedInvoice().str_repeat(' ', 3_000_001 - strlen(markedInvoice())));

    expectProblem($response, 413, '/api/problems/payload-too-large');
    expect($response->getContent())->not->toContain(LOG_MARKER);
});

it('accepts a declared Content-Length equal to the limit', function () {
    $response = postInvoice('', server: ['CONTENT_LENGTH' => '3000000']);

    $response->assertOk();
});

it('checks the content type before the size', function () {
    $response = postInvoice(str_repeat(' ', 3_000_001), 'application/json');

    expectProblem($response, 415, '/api/problems/unsupported-media-type');
});

it('answers other methods with 405 and the allowed method', function (string $method) {
    $response = http()->call($method, VALIDATE_URI);

    expectProblem($response, 405, '/api/problems/method-not-allowed');
    expect($response->headers->get('Allow'))->toBe('POST');
})->with(['GET', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']);

it('answers HEAD with 405 and no body', function () {
    $response = http()->call('HEAD', VALIDATE_URI);

    expect($response->getStatusCode())->toBe(405)
        ->and($response->headers->get('Allow'))->toBe('POST');
});

it('answers unknown paths with 404', function (string $method, string $uri) {
    $response = http()->call($method, $uri);

    expect($response->getStatusCode())->toBe(404)
        ->and($response->headers->get('Content-Type'))->toBe('application/problem+json')
        ->and($response->json('type'))->toBe('/api/problems/not-found')
        ->and(schemaErrors(resolveContractRef(['$ref' => '#/components/schemas/Problem']), (string) $response->getContent()))->toBe([]);
})->with([
    ['GET', '/api/unknown'],
    ['POST', '/api/internal/validate/extra'],
    ['GET', '/'],
    ['GET', '/up'],
]);

it('hides exception details behind a generic 500', function () {
    failingEngine();

    $response = postInvoice(markedInvoice());

    expectProblem($response, 500, '/api/problems/internal-error');
    foreach ([LOG_MARKER, 'RuntimeException', 'Engine failed'] as $leak) {
        expect($response->getContent())->not->toContain($leak);
    }
});

it('describes statuses without a dedicated problem type', function (int $status, string $type) {
    $response = TestResponse::fromBaseResponse(ProblemResponse::from(new HttpException($status)));

    expect($response->json('type'))->toBe($type)
        ->and($response->json('status'))->toBe($status)
        ->and($response->getStatusCode())->toBe($status);
})->with([
    [400, '/api/problems/bad-request'],
    [499, '/api/problems/bad-request'],
    [500, '/api/problems/internal-error'],
    [503, '/api/problems/internal-error'],
]);

it('writes Polish titles and details without request values', function () {
    $response = postInvoice(markedInvoice(), 'application/'.LOG_MARKER);

    expect($response->json('title'))->toBe('Nieobsługiwany typ pliku')
        ->and($response->json('detail'))->toBeString()
        ->and($response->getContent())->toContain('"type":"/api/problems/unsupported-media-type"', 'Nieobsługiwany');
    expect($response->getContent())->not->toContain(LOG_MARKER);
});
