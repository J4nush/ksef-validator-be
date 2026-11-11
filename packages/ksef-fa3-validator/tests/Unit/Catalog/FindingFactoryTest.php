<?php

declare(strict_types=1);

use KsefFa3\Catalog\FindingFactory;
use KsefFa3\Catalog\RuleCatalog;
use KsefFa3\Report\Finding;
use KsefFa3\Report\Severity;
use KsefFa3\Report\SkippedCheck;

covers(FindingFactory::class);

function findingFactory(): FindingFactory
{
    return new FindingFactory(RuleCatalog::fromDirectory(rulesPath()));
}

it('builds a finding from the catalog entry', function () {
    $entry = RuleCatalog::fromDirectory(rulesPath())->entry('TECH-NIP-CHECKSUM');

    expect(findingFactory()->create('TECH-NIP-CHECKSUM', 'Podmiot1/DaneIdentyfikacyjne/NIP', 14))->toEqual(new Finding(
        id: 'TECH-NIP-CHECKSUM',
        severity: Severity::Error,
        field: 'Podmiot1/DaneIdentyfikacyjne/NIP',
        line: 14,
        message: $entry->message,
        fix: $entry->fix,
        docsUrl: '/ksef/bledy/'.$entry->slug,
    ));
});

it('leaves field and line empty by default', function () {
    $finding = findingFactory()->create('XML-EMPTY');

    expect($finding->field)->toBeNull()
        ->and($finding->line)->toBeNull()
        ->and($finding->docsUrl)->toBe(FindingFactory::DOCS_PATH.'pusty-plik')
        ->and(FindingFactory::DOCS_PATH)->toBe('/ksef/bledy/');
});

it('fills in message parameters', function () {
    $finding = findingFactory()->create('XSD-TRUNCATED', params: ['shown' => 100, 'total' => 250]);

    expect($finding->message)->toBe('Pokazano 100 z 250 niezgodności ze wzorem FA(3).');
});

it('fills in parameters in the message and the fix', function () {
    $directory = sys_get_temp_dir().'/finding-factory-'.bin2hex(random_bytes(6));
    mkdir($directory);
    file_put_contents($directory.'/TEST-RULE.yaml', "id: TEST-RULE\nseverity: INFO\ntitle: T\nmessage: 'Masz {a} i {b}, nie {c}.'\nfix: 'Zmień {a}.'\nslug: test\n");

    $finding = (new FindingFactory(RuleCatalog::fromDirectory($directory)))->create('TEST-RULE', params: ['a' => 'jabłko', 'b' => 7]);

    expect($finding->message)->toBe('Masz jabłko i 7, nie {c}.')
        ->and($finding->fix)->toBe('Zmień jabłko.')
        ->and($finding->severity)->toBe(Severity::Info);
});

it('does not reinterpret placeholders inside parameter values', function () {
    $finding = findingFactory()->create('XSD-UNMAPPED', params: ['detail' => 'element {detail} is not expected']);

    expect($finding->message)->toContain('element {detail} is not expected');
});

it('replaces the fix with the first matching hint', function (array $hints, string $fix) {
    $directory = sys_get_temp_dir().'/finding-factory-'.bin2hex(random_bytes(6));
    mkdir($directory);
    file_put_contents($directory.'/TEST-RULE.yaml', "id: TEST-RULE\nseverity: ERROR\ntitle: T\nmessage: M\nfix: 'Ogólnie {a}.'\nslug: test\nhints:\n  P_2: 'Numer {a}.'\n  comma: Przecinek.\n");

    $finding = (new FindingFactory(RuleCatalog::fromDirectory($directory)))->create('TEST-RULE', params: ['a' => 'x'], hints: array_values(array_filter($hints, is_string(...))));

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

    expect(findingFactory()->skipped('KSEF-DUPLICATE'))->toEqual(new SkippedCheck('KSEF-DUPLICATE', $entry->message));
});

it('fills in parameters of a skipped check', function () {
    $directory = sys_get_temp_dir().'/finding-factory-'.bin2hex(random_bytes(6));
    mkdir($directory);
    file_put_contents($directory.'/TEST-RULE.yaml', "id: TEST-RULE\nseverity: INFO\ntitle: T\nmessage: 'Pominięto, bo {reason}.'\nfix: F\nslug: test\n");

    $check = (new FindingFactory(RuleCatalog::fromDirectory($directory)))->skipped('TEST-RULE', ['reason' => 'faktura {reason}']);

    expect($check)->toEqual(new SkippedCheck('TEST-RULE', 'Pominięto, bo faktura {reason}.', ['reason' => 'faktura {reason}']));
});

it('throws a logic exception for an unknown id', function () {
    expect(fn () => findingFactory()->create('NO-SUCH-RULE'))->toThrow(LogicException::class);
});
