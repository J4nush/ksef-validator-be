<?php

declare(strict_types=1);

use KsefFa3\Catalog\FindingFactory;
use KsefFa3\Catalog\RuleCatalog;
use KsefFa3\Clock\FixedClock;
use KsefFa3\Fa3Validator;
use KsefFa3\Pipeline\StageOutcome;
use KsefFa3\Pipeline\ValidationContext;
use KsefFa3\Report\Result;
use KsefFa3\Stage\ParseStage;
use KsefFa3\Xml\LibxmlGuard;

covers(Fa3Validator::class, ParseStage::class, LibxmlGuard::class);

function parseStage(): ParseStage
{
    return new ParseStage(new FindingFactory(RuleCatalog::fromDirectory(rulesPath())), schemaPath());
}

it('reports a malformed document with the line of the first error', function (string $bytes, int $line) {
    $report = validateBytes($bytes);

    expect($report->result())->toBe(Result::Errors)
        ->and(findingIds($report))->toBe(['XML-MALFORMED'])
        ->and($report->findings[0]->line)->toBe($line)
        ->and($report->findings[0]->field)->toBeNull();
})->with([
    'unclosed element' => ['<a><b></a>', 1],
    'mismatch on the third line' => ["<a>\n\n<b></a>\n", 3],
    'several errors' => ["<a>\n<b>\n</c>\n</b>\n<d></a>", 3],
    'second root element' => ["<a/>\n<b/>", 2],
    'undefined entity' => ['<a>&nbsp;</a>', 1],
    'duplicate attribute' => ["<a x='1' x='2'/>", 1],
    'text only' => [" x\n", 1],
]);

it('rejects documents that break XML namespaces', function (string $bytes, int $line) {
    $report = validateBytes($bytes);

    expect(findingIds($report))->toBe(['XML-MALFORMED'])
        ->and($report->findings[0]->line)->toBe($line);
})->with([
    'undeclared prefix' => ['<x:a/>', 1],
    'empty prefixed namespace after a warning' => ["<a xmlns=\"relative\">\n<b xmlns:p=\"\"/></a>", 2],
]);

it('accepts a document with only libxml warnings', function () {
    expect(findingIds(validateBytes('<a xmlns="relative"/>')))->toBe(['DOC-UNSUPPORTED']);
});

it('keeps the parsed document and continues', function () {
    $context = new ValidationContext("<a>\n  <b>tekst</b>\n</a>");

    expect(parseStage()->process($context))->toBe(StageOutcome::Continue)
        ->and($context->document)->toBeInstanceOf(DOMDocument::class)
        ->and($context->document?->documentElement?->nodeName)->toBe('a')
        ->and($context->document?->documentElement?->childNodes->length)->toBe(3)
        ->and($context->findings())->toBe([]);
});

it('stops without a document when parsing fails', function (string $bytes) {
    $context = new ValidationContext($bytes);

    expect(parseStage()->process($context))->toBe(StageOutcome::Stop)
        ->and($context->document)->toBeNull();
})->with([
    'malformed' => '<a>',
    'namespace error' => '<x:a/>',
]);

it('never resolves entities while parsing', function () {
    $report = validateBytes('<a>&lt;&#65;</a>');

    expect(findingIds($report))->toBe(['DOC-UNSUPPORTED']);
});

test('source never enables a dangerous libxml option', function () {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../src', FilesystemIterator::SKIP_DOTS));
    $forbidden = ['LIBXML_NOENT', 'LIBXML_DTDLOAD', 'LIBXML_DTDATTR', 'LIBXML_DTDVALID', 'LIBXML_PARSEHUGE', 'LIBXML_XINCLUDE'];
    $scanned = 0;

    foreach ($files as $file) {
        assert($file instanceof SplFileInfo);
        $code = (string) file_get_contents($file->getPathname());
        $scanned++;

        foreach ($forbidden as $flag) {
            expect($code)->not->toContain($flag);
        }
    }

    expect($scanned)->toBeGreaterThan(0);
});

it('reports line numbers above 65535', function () {
    $bytes = str_replace("  <Podmiot1>\n", str_repeat("\n", 70_000)."  <Podmiot1>\n", fixtureBytes('valid-minimal.xml'));
    $bytes = str_replace('<NIP>1111111111</NIP>', '<NIP>1111111112</NIP>', $bytes);
    $report = (new Fa3Validator(FixedClock::at('2026-11-10T12:00:00Z')))->validate($bytes);

    expect(findingIds($report))->toBe(['TECH-NIP-CHECKSUM'])
        ->and($report->findings[0]->line)->toBe(70_010);
});
