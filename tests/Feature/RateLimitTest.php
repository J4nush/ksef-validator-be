<?php

declare(strict_types=1);

use App\Http\ClientKey;
use App\Http\ProblemResponse;
use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

covers(AppServiceProvider::class, ClientKey::class, ProblemResponse::class);

afterEach(function () {
    TrustProxies::flushState();
});

/**
 * @param  array<string, ?string>  $server
 * @return TestResponse<Response>
 */
function fromAddress(?string $address, string $contentType = 'application/xml', array $server = []): TestResponse
{
    return postInvoice('', $contentType, ['REMOTE_ADDR' => $address, ...$server]);
}

it('allows the configured number of requests per minute and then answers 429', function () {
    config(['ksef.rate_limit.per_minute' => 3]);

    foreach (range(1, 3) as $attempt) {
        fromAddress('203.0.113.7')->assertOk();
    }

    $response = fromAddress('203.0.113.7');

    expect($response->getStatusCode())->toBe(429)
        ->and($response->headers->get('Content-Type'))->toBe('application/problem+json')
        ->and($response->json('type'))->toBe('/api/problems/too-many-requests')
        ->and($response->json('title'))->toBe('Za dużo sprawdzeń')
        ->and((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(60)
        ->and(contractErrors($response))->toBe([]);
});

it('limits by the default of 30 per minute', function () {
    foreach (range(1, 30) as $attempt) {
        fromAddress('203.0.113.7')->assertOk();
    }

    fromAddress('203.0.113.7')->assertStatus(429);
});

it('limits requests per day independently of the minute limit', function () {
    config(['ksef.rate_limit.per_minute' => 100, 'ksef.rate_limit.per_day' => 4]);

    foreach (range(1, 4) as $attempt) {
        fromAddress('203.0.113.7')->assertOk();
    }

    $response = fromAddress('203.0.113.7');

    expect($response->getStatusCode())->toBe(429)
        ->and((int) $response->headers->get('Retry-After'))->toBeGreaterThan(60)->toBeLessThanOrEqual(86_400);
});

it('counts rejected requests towards the limit', function () {
    config(['ksef.rate_limit.per_minute' => 2]);

    fromAddress('203.0.113.7', 'text/plain')->assertStatus(415);
    fromAddress('203.0.113.7', server: ['CONTENT_LENGTH' => '3000001'])->assertStatus(413);

    fromAddress('203.0.113.7')->assertStatus(429);
});

it('checks the limit before the content type', function () {
    config(['ksef.rate_limit.per_minute' => 1]);
    fromAddress('203.0.113.7')->assertOk();

    fromAddress('203.0.113.7', 'text/plain')->assertStatus(429);
});

it('keeps separate counters per client address', function () {
    config(['ksef.rate_limit.per_minute' => 1]);

    fromAddress('203.0.113.7')->assertOk();
    fromAddress('203.0.113.8')->assertOk();
    fromAddress('2001:db8::1')->assertOk();

    fromAddress('203.0.113.7')->assertStatus(429);
});

it('keeps the counters in APCu under keys that do not reveal the address', function () {
    config(['ksef.rate_limit.per_minute' => 5]);

    fromAddress('203.0.113.7')->assertOk();

    $keys = [];

    foreach (new APCUIterator as $entry) {
        assert(is_array($entry) && is_string($entry['key']));
        $keys[] = $entry['key'];
    }

    $client = ClientKey::for('203.0.113.7');
    $limiterKeys = array_map(fn (string $key): string => substr($key, -32), $keys);

    expect($limiterKeys)->toContain(md5('validateminute:'.$client), md5('validateday:'.$client));

    foreach ($keys as $key) {
        foreach (['203.0.113.7', md5('203.0.113.7'), sha1('203.0.113.7'), hash('sha256', '203.0.113.7')] as $leak) {
            expect($key)->not->toContain($leak);
        }
    }
});

it('limits requests without a client address under one shared key', function () {
    config(['ksef.rate_limit.per_minute' => 1]);

    fromAddress(null)->assertOk();

    fromAddress(null)->assertStatus(429);
});

it('derives the client key from the address with HMAC and the application key', function () {
    $key = ClientKey::for('203.0.113.7');

    expect($key)->toBe(hash_hmac('sha256', '203.0.113.7', config()->string('app.key')))
        ->and(ClientKey::for('203.0.113.8'))->not->toBe($key);

    config(['app.key' => 'base64:BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB=']);

    expect(ClientKey::for('203.0.113.7'))->not->toBe($key);
});

it('ignores X-Forwarded-For when no proxy is trusted', function () {
    config(['ksef.rate_limit.per_minute' => 1]);

    fromAddress('172.18.0.5', server: ['HTTP_X_FORWARDED_FOR' => '203.0.113.7'])->assertOk();

    fromAddress('172.18.0.5', server: ['HTTP_X_FORWARDED_FOR' => '203.0.113.8'])->assertStatus(429);
});

it('takes the client address from X-Forwarded-For sent by a trusted proxy', function () {
    config(['ksef.rate_limit.per_minute' => 1, 'trustedproxy.proxies' => ['172.18.0.0/16']]);

    fromAddress('172.18.0.5', server: ['HTTP_X_FORWARDED_FOR' => '203.0.113.7'])->assertOk();
    fromAddress('172.18.0.5', server: ['HTTP_X_FORWARDED_FOR' => '203.0.113.8'])->assertOk();

    fromAddress('172.18.0.5', server: ['HTTP_X_FORWARDED_FOR' => '203.0.113.7'])->assertStatus(429);
});

it('does not let a client spoof its address through a trusted proxy', function () {
    config(['ksef.rate_limit.per_minute' => 1, 'trustedproxy.proxies' => ['172.18.0.0/16']]);

    fromAddress('172.18.0.5', server: ['HTTP_X_FORWARDED_FOR' => '198.51.100.1, 203.0.113.7'])->assertOk();

    fromAddress('172.18.0.5', server: ['HTTP_X_FORWARDED_FOR' => '198.51.100.2, 203.0.113.7'])->assertStatus(429);
});
