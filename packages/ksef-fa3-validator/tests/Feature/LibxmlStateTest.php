<?php

declare(strict_types=1);

use KsefFa3\Clock\FixedClock;
use KsefFa3\Fa3Validator;
use KsefFa3\Report\Result;
use KsefFa3\Stage\ParseStage;
use KsefFa3\Stage\RecognitionStage;
use KsefFa3\Xml\LibxmlGuard;

covers(Fa3Validator::class, ParseStage::class, RecognitionStage::class, LibxmlGuard::class);

beforeEach(fn () => libxml_use_internal_errors(false));

afterEach(fn () => libxml_set_external_entity_loader(null));

it('leaves libxml as it found it between documents', function () {
    $validator = new Fa3Validator(FixedClock::at('2026-11-10T12:00:00Z'));

    $broken = $validator->validate('<a><b></a>');

    expect($broken->result())->toBe(Result::Errors)
        ->and(libxml_use_internal_errors())->toBeFalse()
        ->and(libxml_get_errors())->toBe([])
        ->and(libxml_get_external_entity_loader())->toBeNull();

    $valid = $validator->validate(fixtureBytes('valid-minimal.xml'));

    expect($valid->result())->toBe(Result::Passed)
        ->and(findingIds($valid))->toBe([])
        ->and($valid->document->type)->toBe('FA')
        ->and($valid->document->variant)->toBe('3')
        ->and($valid->document->schema)->toBe('1-0E')
        ->and(libxml_use_internal_errors())->toBeFalse()
        ->and(libxml_get_errors())->toBe([])
        ->and(libxml_get_external_entity_loader())->toBeNull();
});

it('keeps the entity loader of the host application', function () {
    $loader = fn (?string $publicId, string $systemId): ?string => null;
    libxml_set_external_entity_loader($loader);

    $report = (new Fa3Validator(FixedClock::at('2026-11-10T12:00:00Z')))->validate(fixtureBytes('valid-minimal.xml'));

    expect($report->result())->toBe(Result::Passed)
        ->and(libxml_get_external_entity_loader())->toBe($loader);
});
