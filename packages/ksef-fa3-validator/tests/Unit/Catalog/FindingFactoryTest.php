<?php

declare(strict_types=1);

use KsefFa3\Catalog\FindingFactory;
use KsefFa3\Catalog\RuleCatalog;
use KsefFa3\Report\Finding;
use KsefFa3\Report\Layer;
use KsefFa3\Report\Severity;
use KsefFa3\Report\SkippedCheck;

covers(FindingFactory::class);

function testRuleDirectory(string $yaml): string
{
    $directory = sys_get_temp_dir().'/finding-factory-'.bin2hex(random_bytes(6));
    mkdir($directory);
    file_put_contents($directory.'/TEST-RULE.yaml', $yaml);

    return $directory;
}

it('builds a finding from the catalog entry', function () {
    $entry = RuleCatalog::fromDirectory(rulesPath())->entry('TECH-NIP-CHECKSUM');

    expect(findingFactory()->create(Layer::Rules, 'TECH-NIP-CHECKSUM', 'Podmiot1/DaneIdentyfikacyjne/NIP', 14))->toEqual(new Finding(
        id: 'TECH-NIP-CHECKSUM',
        severity: Severity::Error,
        stage: Layer::Rules,
        title: 'Błędny NIP',
        field: 'Podmiot1/DaneIdentyfikacyjne/NIP',
        line: 14,
        message: $entry->message,
        fix: $entry->fix,
        docsUrl: '/ksef/bledy/'.$entry->slug,
        params: ['label' => 'NIP sprzedawcy'],
    ));
});

it('takes the stage from the caller, not from the catalog', function () {
    expect(findingFactory()->create(Layer::Amounts, 'RULE-TRUNCATED', params: ['id' => 'AMT-TOTAL', 'shown' => 100, 'total' => 101])->stage)->toBe(Layer::Amounts)
        ->and(findingFactory()->create(Layer::Rules, 'RULE-TRUNCATED', params: ['id' => 'TECH-NIP-CHECKSUM', 'shown' => 100, 'total' => 101])->stage)->toBe(Layer::Rules);
});

it('leaves field, line and params empty by default', function () {
    $finding = findingFactory()->create(Layer::Security, 'XML-EMPTY');

    expect($finding->field)->toBeNull()
        ->and($finding->line)->toBeNull()
        ->and($finding->params)->toBe([])
        ->and($finding->title)->toBe('Pusty plik')
        ->and($finding->docsUrl)->toBe(FindingFactory::DOCS_PATH.'pusty-plik')
        ->and(FindingFactory::DOCS_PATH)->toBe('/ksef/bledy/');
});

it('fills in message parameters', function () {
    $finding = findingFactory()->create(Layer::Schema, 'XSD-TRUNCATED', params: ['shown' => 100, 'total' => 250]);

    expect($finding->message)->toBe('Pokazano 100 z 250 niezgodności ze wzorem FA(3).')
        ->and($finding->params)->toBe(['shown' => '100', 'total' => '250']);
});

it('fills in parameters in the message and the fix', function () {
    $directory = testRuleDirectory("id: TEST-RULE\nseverity: INFO\nstage: rules\ntitle: T\nmessage: 'Masz {a} i {b}, nie {c}.'\nfix: 'Zmień {a}.'\nslug: test\n");

    $finding = findingFactory($directory)->create(Layer::Rules, 'TEST-RULE', params: ['a' => 'jabłko', 'b' => 7]);

    expect($finding->message)->toBe('Masz jabłko i 7, nie {c}.')
        ->and($finding->fix)->toBe('Zmień jabłko.')
        ->and($finding->severity)->toBe(Severity::Info)
        ->and($finding->title)->toBe('T')
        ->and($finding->params)->toBe(['a' => 'jabłko', 'b' => '7']);
});

it('labels the field of a finding unless the caller already did', function (string $field, ?string $label, array $expected) {
    $params = $label === null ? ['value' => 'x'] : ['value' => 'x', 'label' => $label];

    expect(findingFactory()->create(Layer::Rules, 'TECH-NIP-CHECKSUM', $field, 3, $params)->params)->toBe($expected);
})->with([
    'known path' => ['Podmiot2/DaneIdentyfikacyjne/NIP', null, ['label' => 'NIP nabywcy', 'value' => 'x']],
    'indexed path' => ['Podmiot3[2]/DaneIdentyfikacyjne/NIP', null, ['label' => 'NIP podmiotu trzeciego', 'value' => 'x']],
    'unknown element' => ['Fa/NieznanePole[3]', null, ['label' => 'NieznanePole', 'value' => 'x']],
    'label from the caller' => ['Fa/P_1', 'własna etykieta', ['label' => 'własna etykieta', 'value' => 'x']],
]);

it('does not reinterpret placeholders inside parameter values', function () {
    $finding = findingFactory()->create(Layer::Schema, 'XSD-UNMAPPED', params: ['detail' => 'element {detail} is not expected']);

    expect($finding->message)->toContain('element {detail} is not expected');
});

it('replaces the fix with the first matching hint', function (array $hints, string $fix) {
    $directory = testRuleDirectory("id: TEST-RULE\nseverity: ERROR\nstage: schema\ntitle: T\nmessage: M\nfix: 'Ogólnie {a}.'\nslug: test\nhints:\n  P_2: 'Numer {a}.'\n  comma: Przecinek.\n");

    $finding = findingFactory($directory)->create(Layer::Schema, 'TEST-RULE', params: ['a' => 'x'], hints: array_values(array_filter($hints, is_string(...))));

    expect($finding->fix)->toBe($fix)
        ->and($finding->message)->toBe('M');
})->with([
    'no hints' => [[], 'Ogólnie x.'],
    'unknown hint' => [['P_1'], 'Ogólnie x.'],
    'field hint' => [['P_2'], 'Numer x.'],
    'first known wins' => [['P_1', 'comma', 'P_2'], 'Przecinek.'],
]);

it('builds a skipped check from the catalog entry', function () {
    $entry = RuleCatalog::fromDirectory(rulesPath())->entry('KSEF-DUPLICATE');

    expect(findingFactory()->skipped(null, 'KSEF-DUPLICATE'))->toEqual(new SkippedCheck('KSEF-DUPLICATE', null, $entry->message));
});

it('fills in parameters of a skipped check', function () {
    $directory = testRuleDirectory("id: TEST-RULE\nseverity: INFO\nstage: amounts\ntitle: T\nmessage: 'Pominięto, bo {reason}.'\nfix: F\nslug: test\n");

    $check = findingFactory($directory)->skipped(Layer::Amounts, 'TEST-RULE', ['reason' => 'faktura {reason}']);

    expect($check)->toEqual(new SkippedCheck('TEST-RULE', Layer::Amounts, 'Pominięto, bo faktura {reason}.', ['reason' => 'faktura {reason}']));
});

it('throws a logic exception for an unknown id', function () {
    expect(fn () => findingFactory()->create(Layer::Rules, 'NO-SUCH-RULE'))->toThrow(LogicException::class);
});
