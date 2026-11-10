<?php

declare(strict_types=1);

use KsefFa3\Fa3Validator;
use KsefFa3\Report\DocumentInfo;
use KsefFa3\Report\Finding;
use KsefFa3\Report\Report;
use KsefFa3\Report\Result;
use KsefFa3\Report\Severity;
use KsefFa3\Report\SkippedCheck;
use KsefFa3\Schema\SchemaSource;

covers(Report::class);

function reportFinding(Severity $severity, string $message = 'Komunikat.'): Finding
{
    return new Finding(
        id: 'TEST-'.$severity->value,
        severity: $severity,
        field: null,
        line: null,
        message: $message,
        fix: 'Popraw plik.',
        docsUrl: '/ksef/bledy/test',
    );
}

/**
 * @param  list<Finding>  $findings
 * @param  list<SkippedCheck>  $skipped
 */
function makeReport(array $findings = [], array $skipped = [], bool $unsupported = false): Report
{
    return new Report(
        engineVersion: '9.8.7',
        ruleset: 'test-ruleset',
        source: new SchemaSource('abc123', 'def456', ['faktury/plik.md' => 'fff000']),
        document: new DocumentInfo(type: 'FA', variant: '3', schema: '1-0E', sizeBytes: 1234, sha256Base64Url: 'hash'),
        findings: $findings,
        skipped: $skipped,
        unsupported: $unsupported,
    );
}

it('derives the result from findings', function (array $severities, bool $unsupported, Result $expected) {
    $findings = [];

    foreach ($severities as $severity) {
        assert($severity instanceof Severity);
        $findings[] = reportFinding($severity);
    }

    expect(makeReport($findings, unsupported: $unsupported)->result())->toBe($expected);
})->with([
    'no findings' => [[], false, Result::Passed],
    'info only' => [[Severity::Info], false, Result::Passed],
    'one warning' => [[Severity::Warning], false, Result::Warnings],
    'warning and info' => [[Severity::Info, Severity::Warning], false, Result::Warnings],
    'warning and error' => [[Severity::Warning, Severity::Error], false, Result::Errors],
    'error first' => [[Severity::Error, Severity::Warning], false, Result::Errors],
    'unsupported without findings' => [[], true, Result::Unsupported],
    'unsupported with an error' => [[Severity::Error], true, Result::Unsupported],
    'unsupported with info' => [[Severity::Info], true, Result::Unsupported],
]);

it('uses result values named after the cases', function () {
    expect(array_map(fn (Result $result) => $result->value, Result::cases()))
        ->toBe(['PASSED', 'WARNINGS', 'ERRORS', 'UNSUPPORTED'])
        ->and(array_map(fn (Severity $severity) => $severity->value, Severity::cases()))
        ->toBe(['ERROR', 'WARNING', 'INFO']);
});

it('serializes the report in a fixed key order', function () {
    expect(array_keys(makeReport()->toArray()))->toBe([
        'schemaVersion', 'engineVersion', 'ruleset', 'source', 'result', 'document', 'summary', 'findings', 'skipped',
    ]);
});

it('serializes header, source and document', function () {
    $array = makeReport([reportFinding(Severity::Warning)])->toArray();

    expect($array['schemaVersion'])->toBe(Fa3Validator::REPORT_SCHEMA_VERSION)
        ->and($array['engineVersion'])->toBe('9.8.7')
        ->and($array['ruleset'])->toBe('test-ruleset')
        ->and($array['source'])->toBe(['ksefApiCommit' => 'abc123', 'xsdSha256' => 'def456'])
        ->and($array['result'])->toBe('WARNINGS')
        ->and($array['document'])->toBe([
            'type' => 'FA',
            'variant' => '3',
            'schema' => '1-0E',
            'sizeBytes' => 1234,
            'sha256Base64Url' => 'hash',
        ]);
});

it('serializes an unrecognized document with null fields', function () {
    $report = new Report(
        engineVersion: '1.0.0',
        ruleset: 'r',
        source: new SchemaSource('a', 'b', []),
        document: new DocumentInfo(type: null, variant: null, schema: null, sizeBytes: 0, sha256Base64Url: 'x'),
        findings: [],
        skipped: [],
        unsupported: false,
    );

    expect($report->toArray()['document'])->toBe([
        'type' => null,
        'variant' => null,
        'schema' => null,
        'sizeBytes' => 0,
        'sha256Base64Url' => 'x',
    ]);
});

it('counts findings by severity and skipped checks', function () {
    $report = makeReport(
        [
            reportFinding(Severity::Error),
            reportFinding(Severity::Error),
            reportFinding(Severity::Warning),
            reportFinding(Severity::Info),
            reportFinding(Severity::Info),
            reportFinding(Severity::Info),
        ],
        [new SkippedCheck('A', 'Pierwsza.'), new SkippedCheck('B', 'Druga.')],
    );

    expect($report->toArray()['summary'])->toBe(['errors' => 2, 'warnings' => 1, 'info' => 3, 'skippedChecks' => 2]);
});

it('counts zero for an empty report', function () {
    expect(makeReport()->toArray()['summary'])->toBe(['errors' => 0, 'warnings' => 0, 'info' => 0, 'skippedChecks' => 0]);
});

it('serializes findings and skipped checks in order', function () {
    $report = makeReport(
        [
            new Finding('TECH-NIP-CHECKSUM', Severity::Error, 'Podmiot1/DaneIdentyfikacyjne/NIP', 14, 'Zły NIP.', 'Popraw NIP.', '/ksef/bledy/nip'),
            reportFinding(Severity::Info),
        ],
        [new SkippedCheck('KSEF-DUPLICATE', 'Nie sprawdzono.')],
    );

    expect($report->toArray()['findings'])->toBe([
        [
            'id' => 'TECH-NIP-CHECKSUM',
            'severity' => 'ERROR',
            'field' => 'Podmiot1/DaneIdentyfikacyjne/NIP',
            'line' => 14,
            'message' => 'Zły NIP.',
            'fix' => 'Popraw NIP.',
            'docsUrl' => '/ksef/bledy/nip',
        ],
        [
            'id' => 'TEST-INFO',
            'severity' => 'INFO',
            'field' => null,
            'line' => null,
            'message' => 'Komunikat.',
            'fix' => 'Popraw plik.',
            'docsUrl' => '/ksef/bledy/test',
        ],
    ])->and($report->toArray()['skipped'])->toBe([
        ['id' => 'KSEF-DUPLICATE', 'message' => 'Nie sprawdzono.'],
    ]);
});

it('serializes to JSON with literal Polish letters and slashes', function () {
    $report = makeReport([reportFinding(Severity::Error, 'Zażółć gęślą jaźń')]);
    $json = $report->toJson();

    expect($json)->toContain('Zażółć gęślą jaźń')->toContain('"docsUrl":"/ksef/bledy/test"');
    expect($json)->not->toContain("\n");
    expect(json_decode($json, true, flags: JSON_THROW_ON_ERROR))->toBe($report->toArray());
    expect(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))->toBe($json);
});

it('substitutes invalid UTF-8 instead of failing', function () {
    $json = makeReport([reportFinding(Severity::Error, "zły \xC3\x28 bajt")])->toJson();

    expect($json)->toContain("zły \u{FFFD}( bajt");
});
