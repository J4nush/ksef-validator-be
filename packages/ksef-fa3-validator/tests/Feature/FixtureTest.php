<?php

declare(strict_types=1);

use KsefFa3\Catalog\RuleCatalog;
use KsefFa3\Clock\FixedClock;
use KsefFa3\Fa3Validator;
use KsefFa3\Report\Report;
use KsefFa3\Report\SkippedCheck;

covers(Fa3Validator::class);

const FIXTURE_NOW = '2026-11-10T12:00:00Z';

const IDS_COVERED_ELSEWHERE = [
    'XML-EMPTY',
    'SEC-SIZE-LIMIT',
    'TECH-ENCODING-UTF8',
    'XML-MALFORMED',
    'XSD-TRUNCATED',
    'RULE-TRUNCATED',
    'DOC-UNSUPPORTED',
    'TECH-SIZE-WITHOUT-ATTACHMENT',
    'KSEF-DUPLICATE',
    // The recognition stage rejects a wrong fixed header value before the schema sees it.
    'XSD-FIXED-VALUE',
    // Fallback for libxml messages without a mapping, no supported invoice produces one.
    'XSD-UNMAPPED',
];

/**
 * @return list<string>
 */
function fixtureFiles(): array
{
    return array_map(basename(...), glob(__DIR__.'/../Fixtures/*.xml') ?: []);
}

/**
 * @return array{result: string, findings: list<string>, skipped?: list<string>, sha256Base64Url?: string}
 */
function expectedFor(string $file): array
{
    $path = __DIR__.'/../Fixtures/'.substr($file, 0, -strlen('.xml')).'.expected.json';

    $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($data) || ! is_string($data['result'] ?? null) || ! is_array($data['findings'] ?? null)) {
        throw new UnexpectedValueException("{$file} has an invalid expected report.");
    }

    $findings = array_values(array_filter($data['findings'], is_string(...)));

    if ($findings !== $data['findings']) {
        throw new UnexpectedValueException("{$file} lists findings that are not ids.");
    }

    $expected = ['result' => $data['result'], 'findings' => $findings];

    if (is_array($data['skipped'] ?? null)) {
        $skipped = array_values(array_filter($data['skipped'], is_string(...)));

        if ($skipped !== $data['skipped']) {
            throw new UnexpectedValueException("{$file} lists skipped checks that are not ids.");
        }

        $expected['skipped'] = $skipped;
    }

    if (is_string($data['sha256Base64Url'] ?? null)) {
        $expected['sha256Base64Url'] = $data['sha256Base64Url'];
    }

    return $expected;
}

function validateFixture(string $file): Report
{
    set_error_handler(function (int $level, string $message): never {
        throw new ErrorException($message, 0, $level);
    });

    try {
        return (new Fa3Validator(FixedClock::at(FIXTURE_NOW)))->validate(fixtureBytes($file));
    } finally {
        restore_error_handler();
    }
}

/**
 * @return list<string>
 */
function sortedIds(Report $report): array
{
    $ids = findingIds($report);
    sort($ids);

    return $ids;
}

it('matches the expected report', function (string $file) {
    $expected = expectedFor($file);
    $report = validateFixture($file);

    expect($report->result()->value)->toBe($expected['result'])
        ->and(sortedIds($report))->toBe($expected['findings']);

    if (isset($expected['sha256Base64Url'])) {
        expect($report->document->sha256Base64Url)->toBe($expected['sha256Base64Url']);
    }

    if (isset($expected['skipped'])) {
        expect(array_map(fn (SkippedCheck $check): string => $check->id, $report->skipped))->toBe($expected['skipped']);
    }
})->with(fixtureFiles());

it('explains every finding in polish without the fallback id', function (string $file) {
    $report = validateFixture($file);

    expect(findingIds($report))->not->toContain('XSD-UNMAPPED');

    foreach ($report->findings as $finding) {
        $text = $finding->message.' '.$finding->fix;

        expect(trim($finding->message))->not->toBe('')
            ->and(trim($finding->fix))->not->toBe('')
            ->and($text)->toMatch('/[ąćęłńóśźż]|\b(?:jest|nie|pole|faktury|od|na|do|w|z)\b/iu')
            ->and($text)->not->toMatch('/\b(?:the|is|not|expected|allowed|value)\b/i');
    }
})->with(fixtureFiles());

it('keeps the expected findings sorted', function (string $file) {
    $findings = expectedFor($file)['findings'];
    $sorted = $findings;
    sort($sorted);

    expect($findings)->toBe($sorted);
})->with(fixtureFiles());

it('pairs every fixture with its expected report', function () {
    $fixtures = glob(__DIR__.'/../Fixtures/*.xml') ?: [];
    $expectations = glob(__DIR__.'/../Fixtures/*.expected.json') ?: [];

    expect($fixtures)->not->toBe([]);

    foreach ($fixtures as $fixture) {
        expect(substr($fixture, 0, -strlen('.xml')).'.expected.json')->toBeFile();
    }

    foreach ($expectations as $expectation) {
        expect(substr($expectation, 0, -strlen('.expected.json')).'.xml')->toBeFile();
    }
});

it('exercises every catalog id not covered by generated inputs', function () {
    $found = [];

    foreach (fixtureFiles() as $file) {
        $expected = expectedFor($file);
        $found = [...$found, ...$expected['findings'], ...$expected['skipped'] ?? []];
    }

    $missing = array_values(array_diff(RuleCatalog::fromDirectory(rulesPath())->ids(), IDS_COVERED_ELSEWHERE, $found));

    expect($missing)->toBe([])
        ->and(array_diff(IDS_COVERED_ELSEWHERE, RuleCatalog::fromDirectory(rulesPath())->ids()))->toBe([]);
});

it('points at the second third party with a wrong NIP', function () {
    $finding = validateFixture('bad-nip-podmiot3.xml')->findings[0];

    expect($finding->field)->toBe('Podmiot3[2]/DaneIdentyfikacyjne/NIP')
        ->and($finding->line)->toBe(50);
});

it('points at the attachment', function () {
    $finding = validateFixture('with-attachment.xml')->findings[0];

    expect($finding->field)->toBe('Zalacznik')
        ->and($finding->line)->toBe(67);
});

it('reports a missing issue date only through the schema', function () {
    $report = validateFixture('xsd-missing-p1.xml');

    expect(findingIds($report))->toBe(['XSD-MISSING-ELEMENT'])
        ->and($report->findings[0]->field)->toBe('Fa/P_1')
        ->and($report->findings[0]->message)->toContain('data wystawienia');
});

it('gives a prefixed invoice the same report as an unprefixed one', function () {
    $prefixed = validateFixture('valid-prefixed.xml');
    $plain = validateFixture('valid-full.xml');

    expect(fixtureBytes('valid-prefixed.xml'))->toContain('<fa:Faktura xmlns:fa=')
        ->and($prefixed->result())->toBe($plain->result())
        ->and(findingIds($prefixed))->toBe(findingIds($plain))
        ->and($prefixed->document->type)->toBe('FA');
});

it('keeps line endings of the CRLF fixture', function () {
    $bytes = fixtureBytes('valid-crlf.xml');

    expect(substr_count($bytes, "\r\n"))->toBe(substr_count($bytes, "\n"))
        ->and(substr_count($bytes, "\n"))->toBeGreaterThan(1);
});
