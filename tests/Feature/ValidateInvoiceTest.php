<?php

declare(strict_types=1);

use App\Http\Controllers\ValidateInvoiceController;
use App\Http\Middleware\ReadInvoiceBody;
use App\Http\Middleware\RequireXmlContentType;
use App\Providers\AppServiceProvider;
use KsefFa3\Clock\FixedClock;
use KsefFa3\Fa3Validator;

covers(ValidateInvoiceController::class, RequireXmlContentType::class, ReadInvoiceBody::class, AppServiceProvider::class);

beforeEach(function () {
    app()->instance(Fa3Validator::class, new Fa3Validator(FixedClock::at('2026-11-10T12:00:00Z')));
});

it('returns the engine report with status 200 for every result', function (string $file, string $result) {
    $bytes = invoiceFixture($file);

    $response = postInvoice($bytes);

    $response->assertOk()->assertHeader('Content-Type', 'application/json');
    expect($response->json('result'))->toBe($result)
        ->and($response->getContent())->toBe(app(Fa3Validator::class)->validate($bytes)->toJson())
        ->and(contractErrors($response))->toBe([]);
})->with([
    'valid' => ['valid-minimal.xml', 'PASSED'],
    'warnings' => ['duplicate-line-number.xml', 'WARNINGS'],
    'errors' => ['bad-nip-seller.xml', 'ERRORS'],
    'FA(2)' => ['fa2-header.xml', 'UNSUPPORTED'],
    'schema error' => ['xsd-missing-p2.xml', 'ERRORS'],
]);

it('passes an empty body to the engine', function () {
    $response = postInvoice('');

    $response->assertOk();
    expect($response->json('findings.0.id'))->toBe('XML-EMPTY')
        ->and(contractErrors($response))->toBe([]);
});

it('keeps empty finding params as a JSON object', function () {
    $response = postInvoice('');

    expect($response->getContent())->toContain('"params":{}');
});

it('accepts text/xml and a charset parameter', function (string $contentType) {
    postInvoice(invoiceFixture('valid-minimal.xml'), $contentType)
        ->assertOk()
        ->assertJsonPath('result', 'PASSED');
})->with([
    'text/xml',
    'application/xml; charset=utf-8',
    'Application/XML;charset="UTF-8"',
    ' text/xml ; charset=UTF-8',
]);

it('reports the size and hash of the received bytes', function () {
    $bytes = invoiceFixture('valid-crlf.xml');

    $response = postInvoice($bytes);

    expect($response->json('document.sizeBytes'))->toBe(strlen($bytes))
        ->and($response->json('document.sha256Base64Url'))->toBe(rtrim(strtr(base64_encode(hash('sha256', $bytes, true)), '+/', '-_'), '='));
});

it('validates an invoice of exactly the maximum size', function () {
    $bytes = invoiceFixture('valid-minimal.xml');
    $bytes = str_replace('</Faktura>', str_repeat(' ', 3_000_000 - strlen($bytes)).'</Faktura>', $bytes);

    $response = postInvoice($bytes);

    $response->assertOk();
    expect(strlen($bytes))->toBe(3_000_000)
        ->and($response->json('document.sizeBytes'))->toBe(3_000_000);
});

it('shares one engine instance across requests', function () {
    app()->forgetInstance(Fa3Validator::class);

    expect(app(Fa3Validator::class))->toBe(app(Fa3Validator::class));
});
