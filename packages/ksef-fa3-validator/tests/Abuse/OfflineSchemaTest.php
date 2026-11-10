<?php

declare(strict_types=1);

use KsefFa3\Fa3Validator;
use KsefFa3\Report\Result;
use KsefFa3\Schema\LocalSchemaRepository;
use KsefFa3\Stage\SchemaStage;
use KsefFa3\Xml\LibxmlGuard;

covers(SchemaStage::class, LocalSchemaRepository::class, LibxmlGuard::class);

/**
 * @return array{0: bool, 1: list<string>}
 */
function validateRecordingUris(string $xml, string $schema): array
{
    $document = new DOMDocument;
    $document->loadXML($xml);
    $requested = [];

    [$valid] = LibxmlGuard::run(function () use ($document, $schema, &$requested): bool {
        $guardLoader = libxml_get_external_entity_loader();
        assert($guardLoader !== null);
        libxml_set_external_entity_loader(function (?string $publicId, string $systemId, array $context) use ($guardLoader, &$requested): mixed {
            $requested[] = $systemId;

            return $guardLoader($publicId, $systemId, $context);
        });

        set_error_handler(fn (): bool => true, E_WARNING);

        try {
            return $document->schemaValidateSource($schema, LIBXML_NONET);
        } finally {
            restore_error_handler();
        }
    }, schemaPath());

    return [$valid, $requested];
}

it('loads every schema from local files', function (string $xml, bool $expected) {
    [$valid, $requested] = validateRecordingUris($xml, (new LocalSchemaRepository)->fa3SchemaSource());
    $directory = 'file://'.realpath(schemaPath());

    expect($valid)->toBe($expected)
        ->and($requested)->toBe([
            $directory.'/bazowe/StrukturyDanych_v10-0E.xsd',
            $directory.'/bazowe/ElementarneTypyDanych_v10-0E.xsd',
            $directory.'/bazowe/KodyKrajow_v10-0E.xsd',
        ])
        ->and($requested)->each->not->toStartWith('http');
})->with([
    'valid invoice' => [fn () => fixtureBytes('valid-minimal.xml'), true],
    'invalid invoice' => [fn () => str_replace('<P_1>2026-02-15</P_1>', '', fixtureBytes('valid-minimal.xml')), false],
]);

it('refuses to fetch the remote import of the unmodified MF schema', function () {
    $schema = (string) file_get_contents(schemaPath('schemat_FA(3)_v1-0E.xsd'));
    [$valid, $requested] = validateRecordingUris(fixtureBytes('valid-minimal.xml'), $schema);

    expect($valid)->toBeFalse()
        ->and($requested)->toBe(['http://crd.gov.pl/xml/schematy/dziedzinowe/mf/2022/01/05/eD/DefinicjeTypy/StrukturyDanych_v10-0E.xsd']);
});

it('reports the refused remote import instead of passing', function () {
    $schemas = schemaRepository((string) file_get_contents(schemaPath('schemat_FA(3)_v1-0E.xsd')), schemaPath());
    $report = (new Fa3Validator(schemas: $schemas))->validate(fixtureBytes('valid-minimal.xml'));

    expect($report->result())->toBe(Result::Errors)
        ->and(findingIds($report))->each->toBe('XSD-UNMAPPED');
});
