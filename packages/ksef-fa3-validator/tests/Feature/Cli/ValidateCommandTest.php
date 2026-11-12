<?php

declare(strict_types=1);

use KsefFa3\Catalog\RuleCatalog;
use KsefFa3\Cli\TextRenderer;
use KsefFa3\Cli\ValidateCommand;
use KsefFa3\Clock\FixedClock;
use KsefFa3\Fa3Validator;
use KsefFa3\Schema\LocalSchemaRepository;

covers(ValidateCommand::class, TextRenderer::class);

/**
 * @return array{code: int, stdout: string, stderr: string}
 */
function runValidateCommand(string ...$arguments): array
{
    $stdout = fopen('php://memory', 'w+');
    $stderr = fopen('php://memory', 'w+');
    assert(is_resource($stdout) && is_resource($stderr));

    $command = new ValidateCommand(new Fa3Validator(FixedClock::at('2026-11-10T12:00:00Z')));
    $code = $command->run(['ksef-validate', ...array_values($arguments)], $stdout, $stderr);

    rewind($stdout);
    rewind($stderr);

    return [
        'code' => $code,
        'stdout' => (string) stream_get_contents($stdout),
        'stderr' => (string) stream_get_contents($stderr),
    ];
}

function cliFixture(string $file): string
{
    return dirname(__DIR__, 2).'/Fixtures/'.$file;
}

it('prints the JSON report followed by a single newline', function () {
    $output = runValidateCommand(cliFixture('valid-minimal.xml'), '--json');
    $expected = (new Fa3Validator(FixedClock::at('2026-11-10T12:00:00Z')))->validate(fixtureBytes('valid-minimal.xml'))->toJson();

    expect($output)->toBe(['code' => 0, 'stdout' => $expected."\n", 'stderr' => ''])
        ->and(json_decode($output['stdout'], true, flags: JSON_THROW_ON_ERROR))->toHaveKey('result', 'PASSED');
});

it('accepts the JSON option before the file', function () {
    $output = runValidateCommand('--json', cliFixture('valid-minimal.xml'));

    expect($output['code'])->toBe(0)
        ->and($output['stdout'])->toStartWith('{"schemaVersion":');
});

it('maps the result to the exit code', function (string $file, int $code) {
    expect(runValidateCommand(cliFixture($file), '--json')['code'])->toBe($code)
        ->and(runValidateCommand(cliFixture($file))['code'])->toBe($code);
})->with([
    'passed' => ['valid-minimal.xml', 0],
    'warnings' => ['duplicate-line-number.xml', 0],
    'errors' => ['bad-nip-seller.xml', 1],
    'unsupported' => ['fa2-header.xml', 2],
]);

it('prints a plain text report without the JSON option', function () {
    $output = runValidateCommand(cliFixture('bad-nip-seller.xml'));

    expect($output['code'])->toBe(1)
        ->and($output['stderr'])->toBe('')
        ->and($output['stdout'])->toBe((new TextRenderer)->render(
            (new Fa3Validator(FixedClock::at('2026-11-10T12:00:00Z')))->validate(fixtureBytes('bad-nip-seller.xml')),
        ))
        ->and($output['stdout'])->toContain('Wynik: BŁĘDY (ERRORS)')
        ->and($output['stdout'])->toContain('linia 10: NIP ma niepoprawną cyfrę kontrolną.')
        ->and($output['stdout'])->toContain('Poprawka: Sprawdź NIP')
        ->and($output['stdout'])->not->toContain("\e");
});

it('rejects invalid usage with exit code 3 and an empty stdout', function (string $message, string ...$arguments) {
    $output = runValidateCommand(...$arguments);

    expect($output['code'])->toBe(3)
        ->and($output['stdout'])->toBe('')
        ->and($output['stderr'])->toBe('Błąd: '.$message."\nUżyj ksef-validate --help, aby zobaczyć sposób użycia.\n");
})->with([
    'no argument' => ['nie podano pliku do sprawdzenia.'],
    'only the JSON option' => ['nie podano pliku do sprawdzenia.', '--json'],
    'unknown long option' => ['nieznana opcja --xml.', '--xml', 'faktura.xml'],
    'unknown short option' => ['nieznana opcja -j.', '-j', 'faktura.xml'],
    'two files' => ['podaj dokładnie jeden plik.', 'a.xml', 'b.xml'],
    'catalog with a file' => ['opcja --catalog nie przyjmuje pliku ani innych opcji.', '--catalog', 'faktura.xml'],
    'file before the catalog' => ['opcja --catalog nie przyjmuje pliku ani innych opcji.', 'faktura.xml', '--catalog'],
    'catalog with the JSON option' => ['opcja --catalog nie przyjmuje pliku ani innych opcji.', '--catalog', '--json'],
    'catalog twice' => ['opcja --catalog nie przyjmuje pliku ani innych opcji.', '--catalog', '--catalog'],
]);

/**
 * @return list<array<string, mixed>>
 */
function printedCatalog(): array
{
    $catalog = json_decode(runValidateCommand('--catalog')['stdout'], true, flags: JSON_THROW_ON_ERROR);
    assert(is_array($catalog) && array_is_list($catalog));

    /** @var list<array<string, mixed>> $catalog */
    return $catalog;
}

it('prints the rule catalog as one line of JSON', function () {
    $output = runValidateCommand('--catalog');

    expect($output['code'])->toBe(0)
        ->and($output['stderr'])->toBe('')
        ->and($output['stdout'])->toStartWith('[{"id":"AMT-CURRENCY-VAT",')
        ->and($output['stdout'])->toEndWith("}]\n")
        ->and(substr_count($output['stdout'], "\n"))->toBe(1)
        ->and($output['stdout'])->toContain('Błędny NIP');
});

it('lists every catalog rule once, sorted by id', function () {
    $ids = array_column(printedCatalog(), 'id');
    $expected = RuleCatalog::fromDirectory(rulesPath())->ids();
    sort($expected, SORT_STRING);

    expect($ids)->toBe($expected)
        ->and($ids)->toHaveCount(53);
});

it('prints every field of a catalog rule', function () {
    $rules = array_column(printedCatalog(), null, 'id');

    expect($rules['TECH-NIP-CHECKSUM'])->toBe([
        'id' => 'TECH-NIP-CHECKSUM',
        'severity' => 'ERROR',
        'stage' => 'rules',
        'title' => 'Błędny NIP',
        'message' => 'NIP ma niepoprawną cyfrę kontrolną. KSeF odrzuca taki plik na środowisku produkcyjnym.',
        'fix' => 'Sprawdź NIP w danych firmy i wygeneruj plik ponownie.',
        'slug' => 'nip-cyfra-kontrolna',
        'hints' => [],
    ])
        ->and($rules['KSEF-DUPLICATE']['stage'])->toBeNull()
        ->and($rules['AMT-NOT-CHECKED']['stage'])->toBe('amounts')
        ->and($rules['XSD-ENUM']['hints'])->toHaveKey('KodWaluty', 'Wpisz trzyliterowy kod waluty wielkimi literami, na przykład PLN lub EUR.');
});

it('prints hints as JSON objects even when a rule has none', function () {
    $stdout = runValidateCommand('--catalog')['stdout'];

    expect($stdout)->toContain('"slug":"pusty-plik","hints":{}}')
        ->and($stdout)->not->toContain('"hints":[]');
});

it('prints the catalog with the executable script', function () {
    $script = dirname(__DIR__, 3).'/bin/ksef-validate';
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' --catalog 2>&1', $lines, $exitCode);

    expect($exitCode)->toBe(0)
        ->and($lines)->toHaveCount(1)
        ->and(json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR))->toBe(printedCatalog());
});

it('rejects a file it cannot read with exit code 3 and an empty stdout', function (string $path, string $message) {
    $output = runValidateCommand($path, '--json');

    expect($output['code'])->toBe(3)
        ->and($output['stdout'])->toBe('')
        ->and($output['stderr'])->toBe('Błąd: '.$message."\n");
})->with([
    'missing file' => [cliFixture('missing.xml'), 'nie można odczytać pliku '.cliFixture('missing.xml').'.'],
    'device instead of a regular file' => ['/dev/null', 'nie można odczytać pliku /dev/null.'],
    'directory' => [dirname(cliFixture('valid-minimal.xml')), dirname(cliFixture('valid-minimal.xml')).' to katalog, a nie plik.'],
]);

it('prints usage in Polish', function (string $option) {
    $output = runValidateCommand($option);

    expect($output['code'])->toBe(0)
        ->and($output['stderr'])->toBe('')
        ->and($output['stdout'])->toBe(<<<'TXT'
            Użycie:
              ksef-validate <plik.xml> [--json]
              ksef-validate --catalog
              ksef-validate --version
              ksef-validate --help

            Sprawdza fakturę FA(3) bez połączenia z siecią i bez wysyłania jej do KSeF.

            Opcje:
              --json     raport w formacie JSON
              --catalog  katalog reguł w formacie JSON, bez sprawdzania pliku
              --version  wersja silnika, zestawu reguł i schematu
              --help     ta pomoc

            Kody wyjścia:
              0  plik poprawny albo poprawny z ostrzeżeniami
              1  plik zawiera błędy
              2  dokument nie jest fakturą FA(3)
              3  błąd użycia albo odczytu pliku

            TXT);
})->with(['--help', '-h']);

it('prints help even when other arguments are given', function () {
    expect(runValidateCommand(cliFixture('bad-nip-seller.xml'), '--help'))
        ->toMatchArray(['code' => 0, 'stderr' => ''])
        ->and(runValidateCommand(cliFixture('bad-nip-seller.xml'), '--help')['stdout'])->toStartWith('Użycie:');
});

it('prints the engine, ruleset and schema source versions', function () {
    $source = (new LocalSchemaRepository)->source();
    $output = runValidateCommand('--version');

    expect($output)->toBe([
        'code' => 0,
        'stdout' => 'ksef-validate '.Fa3Validator::ENGINE_VERSION."\n"
            .'Zestaw reguł: '.Fa3Validator::RULESET."\n"
            .'Commit ksef-api: '.$source->ksefApiCommit."\n"
            .'SHA-256 XSD: '.$source->xsdSha256."\n",
        'stderr' => '',
    ]);
});

it('runs as an executable script with the command exit code', function (string $file, int $code, string $needle) {
    $script = dirname(__DIR__, 3).'/bin/ksef-validate';
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg(cliFixture($file)).' --json 2>&1', $lines, $exitCode);

    expect($exitCode)->toBe($code)
        ->and(implode("\n", $lines))->toContain($needle)
        ->and(is_executable($script))->toBeTrue();
})->with([
    'passed' => ['valid-minimal.xml', 0, '"result":"PASSED"'],
    'errors' => ['bad-nip-seller.xml', 1, '"result":"ERRORS"'],
    'missing file' => ['missing.xml', 3, 'nie można odczytać pliku'],
]);

it('reads no more of a huge file than it needs to reject it', function () {
    $path = sys_get_temp_dir().'/ksef-validate-huge-'.bin2hex(random_bytes(4)).'.xml';
    $file = fopen($path, 'w');
    assert(is_resource($file));
    ftruncate($file, 500 * 1024 * 1024);
    fclose($file);
    memory_reset_peak_usage();
    $baseline = memory_get_peak_usage(true);

    try {
        $output = runValidateCommand($path, '--json');
    } finally {
        unlink($path);
    }

    $growth = memory_get_peak_usage(true) - $baseline;

    expect($output['code'])->toBe(1)
        ->and($output['stderr'])->toBe('')
        ->and($output['stdout'])->toContain('"findings":[{"id":"SEC-SIZE-LIMIT",')
        ->and($output['stdout'])->toContain('"sizeBytes":3000001,')
        ->and($growth)->toBeLessThan(64 * 1024 * 1024);
});

it('reads a file at the size limit in full', function () {
    $path = sys_get_temp_dir().'/ksef-validate-limit-'.bin2hex(random_bytes(4)).'.xml';
    file_put_contents($path, '<a>'.str_repeat(' ', 3_000_000 - 7).'</a>');

    try {
        $stdout = runValidateCommand($path, '--json')['stdout'];
    } finally {
        unlink($path);
    }

    expect($stdout)->toContain('"sizeBytes":3000000,')
        ->and($stdout)->toContain('"findings":[{"id":"DOC-UNSUPPORTED",');
});
