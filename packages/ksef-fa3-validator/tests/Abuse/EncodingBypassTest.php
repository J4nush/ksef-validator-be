<?php

declare(strict_types=1);

use KsefFa3\Report\Result;
use KsefFa3\Stage\PreflightStage;

covers(PreflightStage::class);

const XXE = '<?xml version="1.0"?><!DOCTYPE a [<!ENTITY x SYSTEM "file:///etc/passwd">]><a>&x;</a>';

function encodedXxe(string $encoding): string
{
    return (string) mb_convert_encoding(XXE, $encoding, 'UTF-8');
}

it('rejects UTF-16 and UTF-32 before looking for a DOCTYPE', function (string $bytes) {
    $report = validateBytes($bytes);

    expect($report->result())->toBe(Result::Errors)
        ->and(findingIds($report))->toBe(['TECH-ENCODING-UTF8']);
})->with([
    'UTF-16LE with BOM' => fn () => "\xFF\xFE".encodedXxe('UTF-16LE'),
    'UTF-16BE with BOM' => fn () => "\xFE\xFF".encodedXxe('UTF-16BE'),
    'UTF-16BE without BOM' => fn () => encodedXxe('UTF-16BE'),
    'UTF-16LE without BOM' => fn () => encodedXxe('UTF-16LE'),
    'UTF-32LE with BOM' => fn () => "\xFF\xFE\x00\x00".encodedXxe('UTF-32LE'),
    'UTF-32BE with BOM' => fn () => "\x00\x00\xFE\xFF".encodedXxe('UTF-32BE'),
    'UTF-32BE without BOM' => fn () => encodedXxe('UTF-32BE'),
]);

it('rejects a UTF-7 prolog before looking for an encoded DOCTYPE', function () {
    $report = validateBytes('<?xml version="1.0" encoding="UTF-7"?>+ADw-!DOCTYPE a +AFs-+ADw-!ENTITY x SYSTEM +ACI-file:///etc/passwd+ACI-+AD4-+AF0-+AD4-+ADw-a+AD4-+ACY-x;+ADw-/a+AD4-');

    expect($report->result())->toBe(Result::Errors)
        ->and(findingIds($report))->toBe(['TECH-PROLOG-ENCODING']);
});

it('rejects an ISO-8859-2 prolog even when the bytes happen to be ASCII', function () {
    $report = validateBytes('<?xml version="1.0" encoding="ISO-8859-2"?><!DOCTYPE a><a/>');

    expect($report->result())->toBe(Result::Errors)
        ->and(findingIds($report))->toBe(['TECH-PROLOG-ENCODING']);
});
