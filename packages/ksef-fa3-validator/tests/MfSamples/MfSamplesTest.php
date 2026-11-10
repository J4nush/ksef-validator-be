<?php

declare(strict_types=1);

use KsefFa3\Clock\FixedClock;
use KsefFa3\Fa3Validator;
use KsefFa3\Report\Finding;
use KsefFa3\Report\Report;
use KsefFa3\Report\Result;
use KsefFa3\Report\Severity;

covers(Fa3Validator::class);

const MF_SAMPLES_DIRECTORY = __DIR__.'/data';

const MF_SAMPLES_MISSING = 'MF samples are not downloaded, run scripts/fetch-mf-samples.sh.';

function mfSamplesMissing(): bool
{
    return ! is_dir(MF_SAMPLES_DIRECTORY);
}

function validateMfSample(int $number): Report
{
    $bytes = (string) file_get_contents(MF_SAMPLES_DIRECTORY."/przyklad_{$number}.xml");

    set_error_handler(function (int $level, string $message): never {
        throw new ErrorException($message, 0, $level);
    });

    try {
        return (new Fa3Validator(FixedClock::at('2026-11-10T12:00:00Z')))->validate($bytes);
    } finally {
        restore_error_handler();
    }
}

it('has all 26 MF samples', function () {
    $files = array_map(basename(...), glob(MF_SAMPLES_DIRECTORY.'/*.xml') ?: []);

    expect($files)->toEqualCanonicalizing(array_map(fn (int $number): string => "przyklad_{$number}.xml", range(1, 26)));
})->skip(mfSamplesMissing(...), MF_SAMPLES_MISSING)->group('mf-samples');

it('accepts the MF sample without errors', function (int $number) {
    $report = validateMfSample($number);

    $errors = array_values(array_map(
        fn (Finding $finding): string => "{$finding->id} line {$finding->line}: {$finding->message}",
        array_filter($report->findings, fn (Finding $finding): bool => $finding->severity === Severity::Error),
    ));

    expect($errors)->toBe([])
        ->and($report->result())->toBeIn([Result::Passed, Result::Warnings]);
})->with(range(1, 26))->skip(mfSamplesMissing(...), MF_SAMPLES_MISSING)->group('mf-samples');

it('does not flag repeated line numbers in the MF sample', function (int $number) {
    expect(findingIds(validateMfSample($number)))->not->toContain('TECH-LINE-NUMBER-DUPLICATE');
})->with(range(1, 26))->skip(mfSamplesMissing(...), MF_SAMPLES_MISSING)->group('mf-samples');

it('warns that the MF sample with an attachment needs batch mode', function (int $number) {
    expect(findingIds(validateMfSample($number)))->toContain('TECH-ATTACHMENT-BATCH-ONLY');
})->with([24, 25])->skip(mfSamplesMissing(...), MF_SAMPLES_MISSING)->group('mf-samples');

it('hashes MF sample 1 to the value used in its verification link', function () {
    expect(validateMfSample(1)->document->sha256Base64Url)->toBe('X-wQflQVyRs1fImlI-xDPnWk1V37hys4O1lkx9iJbSA');
})->skip(mfSamplesMissing(...), MF_SAMPLES_MISSING)->group('mf-samples');
