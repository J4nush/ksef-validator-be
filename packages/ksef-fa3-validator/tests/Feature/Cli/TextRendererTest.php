<?php

declare(strict_types=1);

use KsefFa3\Cli\TextRenderer;
use KsefFa3\Clock\FixedClock;
use KsefFa3\Fa3Validator;
use KsefFa3\Report\DocumentInfo;
use KsefFa3\Report\Finding;
use KsefFa3\Report\Report;
use KsefFa3\Report\Severity;
use KsefFa3\Report\SkippedCheck;
use KsefFa3\Schema\SchemaSource;

covers(TextRenderer::class);

function renderFixture(string $file): string
{
    return (new TextRenderer)->render((new Fa3Validator(FixedClock::at('2026-11-10T12:00:00Z')))->validate(fixtureBytes($file)));
}

/**
 * @param  list<Finding>  $findings
 * @param  list<SkippedCheck>  $skipped
 */
function textReport(array $findings, array $skipped = [], ?DocumentInfo $document = null, bool $unsupported = false): Report
{
    return new Report(
        engineVersion: '9.8.7',
        ruleset: 'test-ruleset',
        source: new SchemaSource('abc123', 'def456', []),
        document: $document ?? new DocumentInfo(type: 'FA', variant: '3', schema: '1-0E', sizeBytes: 10, sha256Base64Url: 'hash'),
        findings: $findings,
        skipped: $skipped,
        unsupported: $unsupported,
    );
}

function textFinding(Severity $severity, ?int $line = 7, ?string $field = 'Fa/P_1', string $message = 'Komunikat.'): Finding
{
    return new Finding(
        id: 'TEST-'.$severity->value,
        severity: $severity,
        field: $field,
        line: $line,
        message: $message,
        fix: 'Popraw plik.',
        docsUrl: '/ksef/bledy/test',
    );
}

it('renders an invoice with errors', function () {
    expect(renderFixture('bad-nip-seller.xml'))->toBe(<<<'TXT'
        Wynik: BŁĘDY (ERRORS)
        Dokument: FA(3), schemat 1-0E
        SHA-256 pliku (Base64URL): PvSlgiZwRwsihsk0CWQaOsTT0ZVbclH9QsV8sYCIKo8
        Błędy: 1, ostrzeżenia: 0, informacje: 0

        Uwagi:
        BŁĄD TECH-NIP-CHECKSUM, pole Podmiot1/DaneIdentyfikacyjne/NIP
          linia 10: NIP ma niepoprawną cyfrę kontrolną. KSeF odrzuca taki plik na środowisku produkcyjnym.
          Poprawka: Sprawdź NIP w danych firmy i wygeneruj plik ponownie.

        Niesprawdzone:
          KSEF-DUPLICATE: Narzędzie nie sprawdza, czy faktura o tym numerze (NIP sprzedawcy, rodzaj faktury, P_2) jest już w KSeF.

        TXT);
});

it('renders a valid invoice without findings', function () {
    expect(renderFixture('valid-minimal.xml'))
        ->toStartWith("Wynik: POPRAWNY (PASSED)\n")
        ->toContain("Błędy: 0, ostrzeżenia: 0, informacje: 0\n\nBrak uwag.\n\nNiesprawdzone:\n");
});

it('renders an unsupported document', function () {
    expect(renderFixture('fa2-header.xml'))->toBe(<<<'TXT'
        Wynik: NIEOBSŁUGIWANY DOKUMENT (UNSUPPORTED)
        Dokument: nierozpoznany
        SHA-256 pliku (Base64URL): 43nl-Ng0gDauJSjfzvnjhY6PavEkkJP3rAcTlyJSY3Q
        Błędy: 0, ostrzeżenia: 0, informacje: 1

        Uwagi:
        INFORMACJA DOC-FA2-UNSUPPORTED
          To faktura we wzorze FA(2). Narzędzie sprawdza tylko wzór FA(3), który obowiązuje w KSeF.
          Poprawka: Wygeneruj fakturę we wzorze FA(3). Zaktualizuj program do fakturowania, jeśli tworzy jeszcze FA(2).

        TXT);
});

it('labels every result and severity in words', function () {
    $output = (new TextRenderer)->render(textReport([textFinding(Severity::Warning), textFinding(Severity::Info, line: null, field: null)]));

    expect($output)->toStartWith("Wynik: POPRAWNY Z OSTRZEŻENIAMI (WARNINGS)\n")
        ->toContain("Błędy: 0, ostrzeżenia: 1, informacje: 1\n")
        ->toContain("OSTRZEŻENIE TEST-WARNING, pole Fa/P_1\n  linia 7: Komunikat.\n  Poprawka: Popraw plik.\n")
        ->toContain("INFORMACJA TEST-INFO\n  Komunikat.\n  Poprawka: Popraw plik.\n");
});

it('renders a recognised document without a schema version', function () {
    $output = (new TextRenderer)->render(textReport([], document: new DocumentInfo('FA', '3', null, 10, 'hash')));

    expect($output)->toContain("Dokument: FA(3)\n");
});

it('replaces control characters so the report cannot drive the terminal', function () {
    $output = (new TextRenderer)->render(textReport([
        textFinding(Severity::Error, message: "Zły\e[31m znak\x9B\0\nkoniec \u{9B}x \u{85}"),
    ]));

    expect($output)->toContain("  linia 7: Zły?[31m znak\x9B??koniec ?x ?\n")
        ->and(str_contains($output, "\e"))->toBeFalse()
        ->and(str_contains($output, "\u{9B}"))->toBeFalse();
});

it('replaces characters that reorder or break the terminal line', function (string $character) {
    $output = (new TextRenderer)->render(textReport([textFinding(Severity::Error, message: "a{$character}b")]));

    expect($output)->toContain("  linia 7: a?b\n")
        ->and(str_contains($output, $character))->toBeFalse();
})->with([
    'U+200E' => "\u{200E}",
    'U+200F' => "\u{200F}",
    'U+202A' => "\u{202A}",
    'U+202B' => "\u{202B}",
    'U+202C' => "\u{202C}",
    'U+202D' => "\u{202D}",
    'U+202E' => "\u{202E}",
    'U+2066' => "\u{2066}",
    'U+2067' => "\u{2067}",
    'U+2068' => "\u{2068}",
    'U+2069' => "\u{2069}",
    'U+2028' => "\u{2028}",
    'U+2029' => "\u{2029}",
]);

it('keeps characters next to the replaced ranges', function (string $character) {
    $output = (new TextRenderer)->render(textReport([textFinding(Severity::Error, message: "a{$character}b")]));

    expect($output)->toContain("  linia 7: a{$character}b\n");
})->with([
    'U+200D' => "\u{200D}",
    'U+2030' => "\u{2030}",
    'U+2027' => "\u{2027}",
    'U+202F' => "\u{202F}",
    'U+2065' => "\u{2065}",
    'U+206A' => "\u{206A}",
    'U+20AC' => "\u{20AC}",
]);
