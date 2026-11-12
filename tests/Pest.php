<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;
use KsefFa3\Clock\Clock;
use KsefFa3\Fa3Validator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Pest\TestSuite;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

const VALIDATE_URI = '/api/internal/validate';

const LOG_MARKER = 'ZNACZNIK-7f3a';

function invoiceFixture(string $file): string
{
    return (string) file_get_contents(base_path('packages/ksef-fa3-validator/tests/Fixtures/'.$file));
}

function http(): TestCase
{
    $test = TestSuite::getInstance()->test;
    assert($test instanceof TestCase);

    return $test;
}

function markedInvoice(): string
{
    return str_replace('Przykładowa usługa', LOG_MARKER, invoiceFixture('valid-minimal.xml'));
}

/**
 * @param  array<string, ?string>  $server
 * @return TestResponse<Response>
 */
function postInvoice(string $bytes, ?string $contentType = 'application/xml', array $server = []): TestResponse
{
    $server['CONTENT_TYPE'] = $contentType ?? '';

    return http()->call('POST', VALIDATE_URI, server: $server, content: $bytes);
}

/**
 * @return array<string, mixed>
 */
function openapi(): array
{
    $document = Yaml::parseFile(base_path('openapi/openapi.yaml'));
    assert(is_array($document));

    /** @var array<string, mixed> $document */
    return $document;
}

/**
 * @return array<string, mixed>
 */
function contractResponse(int $status): array
{
    return resolveContractRef(['$ref' => '#/paths/~1api~1internal~1validate/post/responses/'.$status]);
}

/**
 * @param  array<string, mixed>  $node
 * @return array<string, mixed>
 */
function resolveContractRef(array $node): array
{
    if (! isset($node['$ref']) || ! is_string($node['$ref'])) {
        return $node;
    }

    $reference = $node['$ref'];

    if (! str_starts_with($reference, '#/')) {
        $schema = json_decode((string) file_get_contents(base_path('openapi/'.$reference)), true, flags: JSON_THROW_ON_ERROR);
        assert(is_array($schema));

        /** @var array<string, mixed> $schema */
        return $schema;
    }

    $target = openapi();

    foreach (explode('/', substr($reference, 2)) as $segment) {
        $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
        assert(is_array($target) && array_key_exists($segment, $target));
        $target = $target[$segment];
    }

    assert(is_array($target));

    /** @var array<string, mixed> $target */
    return resolveContractRef($target);
}

/**
 * @param  TestResponse<Response>  $response
 * @return list<string>
 */
function contractErrors(TestResponse $response): array
{
    $status = $response->getStatusCode();
    $mediaType = strtok((string) $response->headers->get('Content-Type'), ';');
    $content = contractResponse($status)['content'] ?? [];
    assert(is_array($content));

    if (! isset($content[$mediaType]) || ! is_array($content[$mediaType])) {
        return ["Status {$status} has no {$mediaType} body in the contract."];
    }

    $schema = $content[$mediaType]['schema'];
    assert(is_array($schema));

    /** @var array<string, mixed> $schema */
    return schemaErrors(resolveContractRef($schema), (string) $response->getContent());
}

/**
 * @param  array<string, mixed>  $schema
 * @return list<string>
 */
function schemaErrors(array $schema, string $json): array
{
    $validator = new Validator;
    $validator->setMaxErrors(20);
    $schemaObject = json_decode((string) json_encode($schema, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
    assert(is_object($schemaObject));
    $result = $validator->validate(json_decode($json, flags: JSON_THROW_ON_ERROR), $schemaObject);
    $error = $result->error();

    if ($error === null) {
        return [];
    }

    $errors = [];

    foreach ((new ErrorFormatter)->formatFlat($error) as $message) {
        $errors[] = is_string($message) ? $message : 'Unreadable schema error.';
    }

    return $errors;
}

function failingEngine(): void
{
    $clock = new class implements Clock
    {
        public function now(): DateTimeImmutable
        {
            throw new RuntimeException('Engine failed on '.markedInvoice());
        }
    };

    app()->instance(Fa3Validator::class, new Fa3Validator($clock));
}
