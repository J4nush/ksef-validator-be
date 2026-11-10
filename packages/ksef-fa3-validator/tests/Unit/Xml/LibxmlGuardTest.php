<?php

declare(strict_types=1);

use KsefFa3\Xml\LibxmlGuard;

covers(LibxmlGuard::class);

function guardRoot(): string
{
    $root = sys_get_temp_dir().'/libxml-guard-'.getmypid();

    if (! is_dir($root)) {
        mkdir($root.'/allowed dir/nested', recursive: true);
        mkdir($root.'/allowed dir-sibling');
        file_put_contents($root.'/allowed dir/inside.xsd', '<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"/>');
        file_put_contents($root.'/allowed dir/nested/deeper.xsd', '');
        file_put_contents($root.'/allowed dir-sibling/neighbour.xsd', '');
        file_put_contents($root.'/outside.xsd', '<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"/>');
    }

    return $root;
}

function allowedDirectory(): string
{
    return guardRoot().'/allowed dir';
}

afterEach(function () {
    libxml_use_internal_errors(false);
    libxml_clear_errors();
    libxml_set_external_entity_loader(null);
});

afterAll(fn () => exec('rm -rf '.escapeshellarg(guardRoot())));

function resolveInside(string $allowedDirectory, string $systemId): mixed
{
    [$resolved] = LibxmlGuard::run(function () use ($systemId): mixed {
        $loader = libxml_get_external_entity_loader();
        assert($loader !== null);

        return $loader(null, $systemId, []);
    }, $allowedDirectory);

    return $resolved;
}

it('returns the result of the operation', function () {
    [$result, $errors] = LibxmlGuard::run(fn (): string => 'done', allowedDirectory());

    expect($result)->toBe('done')
        ->and($errors)->toBe([]);
});

it('collects libxml errors raised by the operation', function () {
    [$loaded, $errors] = LibxmlGuard::run(fn (): bool => (new DOMDocument)->loadXML("<a>\n<b></a>"), allowedDirectory());

    expect($loaded)->toBeFalse()
        ->and($errors)->not->toBeEmpty()
        ->and($errors[0])->toBeInstanceOf(LibXMLError::class)
        ->and($errors[0]->line)->toBe(2);
});

it('does not return errors left over from earlier libxml calls', function () {
    libxml_use_internal_errors(true);
    (new DOMDocument)->loadXML('<a>');

    [, $errors] = LibxmlGuard::run(fn (): bool => true, allowedDirectory());

    expect($errors)->toBe([]);
});

it('uses internal errors only while the operation runs', function (bool $before) {
    libxml_use_internal_errors($before);

    [$during] = LibxmlGuard::run(fn (): bool => libxml_use_internal_errors(), allowedDirectory());

    expect($during)->toBeTrue()
        ->and(libxml_use_internal_errors())->toBe($before);
})->with([
    'disabled before' => false,
    'enabled before' => true,
]);

it('leaves no errors and the default entity loader behind', function () {
    libxml_use_internal_errors(true);

    LibxmlGuard::run(fn (): bool => (new DOMDocument)->loadXML('<a>'), allowedDirectory());

    expect(libxml_get_errors())->toBe([])
        ->and(libxml_get_external_entity_loader())->toBeNull();
});

it('restores the state when the operation throws', function () {
    libxml_use_internal_errors(false);

    expect(fn () => LibxmlGuard::run(function (): never {
        (new DOMDocument)->loadXML('<a>');

        throw new RuntimeException('failed');
    }, allowedDirectory()))->toThrow(RuntimeException::class, 'failed');

    libxml_use_internal_errors(true);

    expect(libxml_get_errors())->toBe([])
        ->and(libxml_get_external_entity_loader())->toBeNull();
});

it('restores the entity loader the caller had set', function (bool $throws) {
    $loader = fn (?string $publicId, string $systemId): ?string => null;
    libxml_set_external_entity_loader($loader);

    $operation = function () use ($throws, $loader): bool {
        if ($throws) {
            throw new RuntimeException('failed');
        }

        return libxml_get_external_entity_loader() !== $loader;
    };

    try {
        [$during] = LibxmlGuard::run($operation, allowedDirectory());
    } catch (RuntimeException) {
        $during = true;
    }

    expect($during)->toBeTrue()
        ->and(libxml_get_external_entity_loader())->toBe($loader);
})->with([
    'returns' => false,
    'throws' => true,
]);

it('clears its own errors and restores the internal errors flag the caller had', function () {
    libxml_use_internal_errors(true);
    (new DOMDocument)->loadXML('<a>');

    expect(libxml_get_errors())->not->toBe([]);

    [, $errors] = LibxmlGuard::run(fn (): bool => (new DOMDocument)->loadXML('<b>'), allowedDirectory());

    expect($errors)->not->toBe([])
        ->and(libxml_get_errors())->toBe([])
        ->and(libxml_use_internal_errors())->toBeTrue();
});

it('resolves files inside the allowed directory', function (string $systemId, string $file) {
    $systemId = strtr($systemId, ['{dir}' => allowedDirectory(), '{escaped}' => str_replace(' ', '%20', allowedDirectory())]);

    expect(resolveInside(allowedDirectory(), $systemId))->toBe('file://'.str_replace(' ', '%20', (string) realpath(allowedDirectory().'/'.$file)));
})->with([
    'plain path' => ['{dir}/inside.xsd', 'inside.xsd'],
    'file URI' => ['file://{dir}/inside.xsd', 'inside.xsd'],
    'file URI with an escaped space' => ['file://{escaped}/inside.xsd', 'inside.xsd'],
    'nested directory' => ['file://{dir}/nested/deeper.xsd', 'nested/deeper.xsd'],
    'dot segments that stay inside' => ['{dir}/nested/../inside.xsd', 'inside.xsd'],
]);

it('refuses everything outside the allowed directory', function (string $systemId) {
    expect(resolveInside(allowedDirectory(), strtr($systemId, ['{dir}' => allowedDirectory()])))->toBeNull();
})->with([
    'http' => 'http://crd.gov.pl/xml/schematy/StrukturyDanych_v10-0E.xsd',
    'https' => 'https://example.invalid/a.xsd',
    'ftp' => 'ftp://example.invalid/a.xsd',
    'php filter' => 'php://filter/resource={dir}/inside.xsd',
    'data URI' => 'data:text/plain,abc',
    'system file' => '/etc/passwd',
    'system file URI' => 'file:///etc/passwd',
    'parent directory' => '{dir}/../outside.xsd',
    'escaped dot segments' => 'file://{dir}/%2E%2E/outside.xsd',
    'sibling with the same prefix' => '{dir}-sibling/neighbour.xsd',
    'the directory itself' => '{dir}',
    'missing file' => '{dir}/missing.xsd',
    'file URI with a host' => 'file://localhost{dir}/inside.xsd',
    'relative path' => 'inside.xsd',
]);

it('refuses everything when the allowed directory does not exist', function () {
    expect(resolveInside(guardRoot().'/missing', '/etc/passwd'))->toBeNull()
        ->and(resolveInside(guardRoot().'/missing', allowedDirectory().'/inside.xsd'))->toBeNull();
});

it('lets libxml load only schemas inside the allowed directory', function (string $file, bool $valid) {
    $file = guardRoot().'/'.$file;
    $schema = '<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema">'
        .'<xs:include schemaLocation="file://'.str_replace(' ', '%20', $file).'"/>'
        .'<xs:element name="a"/></xs:schema>';
    $document = new DOMDocument;
    $document->loadXML('<a/>');

    $warnings = [];
    set_error_handler(function (int $level, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    });

    try {
        [$result, $errors] = LibxmlGuard::run(fn (): bool => $document->schemaValidateSource($schema, LIBXML_NONET), allowedDirectory());
    } finally {
        restore_error_handler();
    }

    expect($result)->toBe($valid)
        ->and($errors === [])->toBe($valid)
        ->and($warnings === [])->toBe($valid);
})->with([
    'inside' => ['allowed dir/inside.xsd', true],
    'outside' => ['outside.xsd', false],
]);
