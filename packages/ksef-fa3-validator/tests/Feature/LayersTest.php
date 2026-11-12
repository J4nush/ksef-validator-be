<?php

declare(strict_types=1);

use KsefFa3\Fa3Validator;

covers(Fa3Validator::class);

it('stops after the security layer for an empty file', function () {
    expect(layersOf(validateAt('')))->toBe([
        'security' => 'errors',
        'recognition' => 'not_run',
        'schema' => 'not_run',
        'rules' => 'not_run',
        'amounts' => 'not_run',
    ]);
});

it('stops after the security layer for malformed XML', function () {
    expect(layersOf(validateAt('<Faktura>')))->toBe([
        'security' => 'errors',
        'recognition' => 'not_run',
        'schema' => 'not_run',
        'rules' => 'not_run',
        'amounts' => 'not_run',
    ]);
});

it('marks recognition as unsupported for an FA(2) invoice', function () {
    expect(layersOf(validateAt(fixtureBytes('fa2-header.xml'))))->toBe([
        'security' => 'passed',
        'recognition' => 'unsupported',
        'schema' => 'not_run',
        'rules' => 'not_run',
        'amounts' => 'not_run',
    ]);
});

it('leaves the amounts of a correction with a schema error unchecked', function () {
    $bytes = str_replace('<KodWaluty>PLN</KodWaluty>', '<KodWaluty>pln</KodWaluty>', fixtureBytes('valid-correction.xml'));
    $report = validateAt($bytes);

    expect(findingIds($report))->toBe(['XSD-ENUM'])
        ->and(layersOf($report))->toBe([
            'security' => 'passed',
            'recognition' => 'passed',
            'schema' => 'errors',
            'rules' => 'passed',
            'amounts' => 'not_checked',
        ]);
});

it('passes every layer of a valid invoice', function () {
    expect(layersOf(validateAt(fixtureBytes('valid-full.xml'))))->toBe([
        'security' => 'passed',
        'recognition' => 'passed',
        'schema' => 'passed',
        'rules' => 'passed',
        'amounts' => 'passed',
    ]);
});

it('reports an amount warning in the amounts layer', function () {
    expect(layersOf(validateAt(fixtureBytes('amt-total.xml'))))->toBe([
        'security' => 'passed',
        'recognition' => 'passed',
        'schema' => 'passed',
        'rules' => 'passed',
        'amounts' => 'warnings',
    ]);
});

it('keeps checking after a byte order mark', function () {
    expect(layersOf(validateAt(fixtureBytes('bom.xml'))))->toBe([
        'security' => 'errors',
        'recognition' => 'passed',
        'schema' => 'passed',
        'rules' => 'passed',
        'amounts' => 'passed',
    ]);
});

it('reports parse errors in the security layer', function () {
    $report = validateAt('<Faktura>');

    expect($report->findings[0]->id)->toBe('XML-MALFORMED')
        ->and($report->findings[0]->stage->value)->toBe('security');
});

it('reports truncated amount findings in the amounts layer', function () {
    $rows = '';

    for ($line = 1; $line <= 101; $line++) {
        $rows .= "<FaWiersz><NrWierszaFa>{$line}</NrWierszaFa><P_7>Usługa</P_7><P_8B>1</P_8B><P_9A>10.00</P_9A><P_11>11.00</P_11><P_12>23</P_12></FaWiersz>";
    }

    $bytes = (string) preg_replace('#<FaWiersz>.*</FaWiersz>#s', $rows, fixtureBytes('valid-minimal.xml'));
    $report = validateAt($bytes);
    $truncated = array_values(array_filter($report->findings, fn ($finding): bool => $finding->id === 'RULE-TRUNCATED'));

    expect($truncated)->not->toBe([])
        ->and(array_unique(array_map(fn ($finding): string => $finding->stage->value, $truncated)))->toBe(['amounts']);
});
