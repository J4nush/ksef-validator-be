<?php

declare(strict_types=1);

use KsefFa3\Rule\NipChecksumRule;

covers(NipChecksumRule::class);

function subject(string $name, string $identifier, string $element = 'NIP'): string
{
    return "<{$name}><DaneIdentyfikacyjne><{$element}>{$identifier}</{$element}></DaneIdentyfikacyjne></{$name}>";
}

it('reports a seller NIP with a wrong check digit', function () {
    $xml = <<<'XML'
        <Faktura xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/">
          <Naglowek/>
          <Podmiot1>
            <DaneIdentyfikacyjne>
              <NIP>1111111112</NIP>
            </DaneIdentyfikacyjne>
          </Podmiot1>
        </Faktura>
        XML;
    $violations = violationsOf(new NipChecksumRule, $xml);

    expect(violationSummary($violations))->toBe([['TECH-NIP-CHECKSUM', 'Podmiot1/DaneIdentyfikacyjne/NIP', 5]])
        ->and($violations[0]->params)->toBe(['value' => '1111111112', 'expected' => '1']);
});

it('passes the NIP and the correct check digit to the finding', function (string $identifier, string $element, array $params) {
    $violations = violationsOf(new NipChecksumRule, fa3Xml(subject('Podmiot3', $identifier, $element)));

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->params)->toBe($params);
})->with([
    'NIP' => [' 2222222221 ', 'NIP', ['value' => '2222222221', 'expected' => '2']],
    'NIP without a possible check digit' => ['0005000001', 'NIP', ['value' => '0005000001']],
    'internal identifier' => ['1111111112-00001', 'IDWew', ['value' => '1111111112-00001', 'expected' => '1']],
    'internal identifier without a possible check digit' => ['0005000001-00001', 'IDWew', ['value' => '0005000001-00001']],
]);

it('checks every subject that carries a NIP', function () {
    $xml = fa3Xml(
        subject('Podmiot1', '1111111111')
        .subject('Podmiot2', '1111111112')
        .subject('Podmiot3', '2222222222')
        .subject('Podmiot3', '1111111112')
        .subject('Podmiot3', '1111111111')
        .subject('PodmiotUpowazniony', ' 1111111112 ')
    );

    expect(violationSummary(violationsOf(new NipChecksumRule, $xml)))->toBe([
        ['TECH-NIP-CHECKSUM', 'Podmiot2/DaneIdentyfikacyjne/NIP', 1],
        ['TECH-NIP-CHECKSUM', 'Podmiot3[2]/DaneIdentyfikacyjne/NIP', 1],
        ['TECH-NIP-CHECKSUM', 'PodmiotUpowazniony/DaneIdentyfikacyjne/NIP', 1],
    ]);
});

it('accepts valid NIPs', function () {
    $xml = fa3Xml(subject('Podmiot1', '1111111111').subject('Podmiot2', '2222222222'));

    expect(violationsOf(new NipChecksumRule, $xml))->toBe([]);
});

it('checks the NIP inside an internal identifier of a third party', function (string $identifier, array $expected) {
    $xml = fa3Xml(subject('Podmiot3', $identifier, 'IDWew'));

    expect(violationSummary(violationsOf(new NipChecksumRule, $xml)))->toBe($expected);
})->with([
    'wrong check digit' => ['1111111112-00001', [['TECH-INTERNAL-ID-CHECKSUM', 'Podmiot3/DaneIdentyfikacyjne/IDWew', 1]]],
    'surrounding whitespace' => [' 1111111112-00001 ', [['TECH-INTERNAL-ID-CHECKSUM', 'Podmiot3/DaneIdentyfikacyjne/IDWew', 1]]],
    'valid' => ['1111111111-00001', []],
    'without suffix' => ['1111111112', []],
    'short suffix' => ['1111111112-0001', []],
    'text before' => ['X1111111112-00001', []],
    'text after' => ['1111111112-00001X', []],
]);

it('leaves identifiers that do not match the schema pattern to the schema check', function (string $nip) {
    expect(violationsOf(new NipChecksumRule, fa3Xml(subject('Podmiot1', $nip))))->toBe([]);
})->with([
    'letter' => '11111X1111',
    'too short' => '111111112',
    'too long' => '11111111112',
    'text before' => 'X1111111112',
    'text after' => '1111111112X',
    'empty' => '',
]);

it('does not check NIPs outside the invoice subjects', function () {
    $xml = fa3Xml(
        '<Fa><Podmiot1K>'.subject('Podmiot1', '1111111112').'</Podmiot1K></Fa>'
        .'<Fa><Podmiot1K><DaneIdentyfikacyjne><NIP>1111111112</NIP></DaneIdentyfikacyjne></Podmiot1K></Fa>'
        .subject('Podmiot2K', '1111111112')
    );

    expect(violationsOf(new NipChecksumRule, $xml))->toBe([]);
});

it('ignores subjects outside the FA(3) namespace', function () {
    $xml = fa3Xml('<Podmiot1 xmlns="urn:other"><DaneIdentyfikacyjne><NIP>1111111112</NIP></DaneIdentyfikacyjne></Podmiot1>');

    expect(violationsOf(new NipChecksumRule, $xml))->toBe([]);
});

it('ignores a document without subjects', function () {
    expect(violationsOf(new NipChecksumRule, fa3Xml('<Naglowek/>')))->toBe([]);
});
