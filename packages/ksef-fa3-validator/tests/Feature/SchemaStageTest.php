<?php

declare(strict_types=1);

use KsefFa3\Catalog\FindingFactory;
use KsefFa3\Catalog\RuleCatalog;
use KsefFa3\Fa3Validator;
use KsefFa3\Pipeline\StageOutcome;
use KsefFa3\Pipeline\ValidationContext;
use KsefFa3\Report\Finding;
use KsefFa3\Report\Result;
use KsefFa3\Schema\LocalSchemaRepository;
use KsefFa3\Stage\SchemaStage;
use KsefFa3\Xml\FileUri;
use KsefFa3\Xml\InvoiceDocument;
use KsefFa3\Xml\LibxmlGuard;
use KsefFa3\Xsd\XsdErrorClassifier;

covers(Fa3Validator::class, SchemaStage::class, LocalSchemaRepository::class, LibxmlGuard::class, FileUri::class);

function schemaStage(): SchemaStage
{
    return new SchemaStage(
        new FindingFactory(RuleCatalog::fromDirectory(rulesPath())),
        (new LocalSchemaRepository)->fa3SchemaSource(),
        schemaPath(),
        XsdErrorClassifier::fromDirectories(dirname(__DIR__, 2).'/resources/fields', schemaPath()),
    );
}

function withRows(int $rows, string $search, string $replace): string
{
    $minimal = fixtureBytes('valid-minimal.xml');
    $start = (int) strpos($minimal, "\n    <FaWiersz>");
    $row = substr($minimal, $start, (int) strpos($minimal, '</FaWiersz>') + strlen('</FaWiersz>') - $start);

    return str_replace($row, str_repeat(str_replace($search, $replace, $row), $rows), $minimal);
}

function withInvalidLineNumbers(int $rows): string
{
    return withRows($rows, '<NrWierszaFa>1</NrWierszaFa>', '<NrWierszaFa>abc</NrWierszaFa>');
}

function temporaryDirectory(string $name): string
{
    return sys_get_temp_dir().'/'.$name.'-'.bin2hex(random_bytes(4));
}

it('accepts the minimal valid invoice', function () {
    $report = validateBytes(fixtureBytes('valid-minimal.xml'));

    expect(findingIds($report))->toBe([])
        ->and($report->result())->toBe(Result::Passed);
});

it('reports a missing element at the line of the element that follows it', function () {
    $minimal = fixtureBytes('valid-minimal.xml');
    $bytes = str_replace("    <WariantFormularza>3</WariantFormularza>\n", '', $minimal);
    $report = validateBytes($bytes);

    expect($bytes)->not->toBe($minimal)
        ->and($report->result())->toBe(Result::Errors)
        ->and(findingIds($report))->toBe(['XSD-MISSING-ELEMENT'])
        ->and($report->findings[0]->line)->toBe(5)
        ->and($report->findings[0]->field)->toBe('Naglowek/WariantFormularza')
        ->and($report->findings[0]->message)->toStartWith('Brakuje wymaganego pola: wariant formularza (sekcja: nagłówek).');
});

it('shows the first 100 schema errors and how many there were', function (int $rows, int $shown) {
    $report = validateBytes(withInvalidLineNumbers($rows));
    $ids = findingIds($report);
    $firstRowLine = substr_count(explode('<NrWierszaFa>abc', withInvalidLineNumbers($rows))[0], "\n") + 1;

    expect(array_count_values($ids))->toBe(['XSD-INTEGER-FORMAT' => $shown, 'XSD-TRUNCATED' => 1])
        ->and($ids[$shown])->toBe('XSD-TRUNCATED')
        ->and($report->findings[$shown]->message)->toBe("Pokazano {$shown} z {$rows} niezgodności ze wzorem FA(3).")
        ->and($report->findings[$shown]->line)->toBeNull()
        ->and($report->findings[0]->line)->toBe($firstRowLine)
        ->and($report->findings[1]->line)->toBe($firstRowLine + 9)
        ->and($report->findings[0]->message)->toContain('„abc”');
})->with([
    '150 rows' => [150, 100],
    '101 rows' => [101, 100],
]);

it('shows every schema error up to 100', function () {
    $report = validateBytes(withInvalidLineNumbers(100));

    expect(array_count_values(findingIds($report)))->toBe(['XSD-INTEGER-FORMAT' => 100]);
});

it('ignores libxml warnings', function () {
    $directory = temporaryDirectory('ksef-fa3-warning');
    mkdir($directory);
    $empty = '<xsd:schema xmlns:xsd="http://www.w3.org/2001/XMLSchema" targetNamespace="urn:example:empty"/>';
    file_put_contents($directory.'/first.xsd', $empty);
    file_put_contents($directory.'/second.xsd', $empty);
    $schema = '<xsd:schema xmlns:xsd="http://www.w3.org/2001/XMLSchema" targetNamespace="'.InvoiceDocument::FA3_NAMESPACE.'">'
        .'<xsd:import namespace="urn:example:empty" schemaLocation="'.FileUri::fromPath($directory.'/first.xsd').'"/>'
        .'<xsd:import namespace="urn:example:empty" schemaLocation="'.FileUri::fromPath($directory.'/second.xsd').'"/>'
        .'<xsd:element name="Faktura"/></xsd:schema>';

    try {
        $report = (new Fa3Validator(schemas: schemaRepository($schema, $directory)))->validate(fixtureBytes('valid-minimal.xml'));
    } finally {
        removeDirectory($directory);
    }

    expect(findingIds($report))->toBe([]);
});

it('reports a schema that cannot be compiled without raising a PHP warning', function (string $schema) {
    $previous = function (int $level, string $message): never {
        throw new ErrorException($message, 0, $level);
    };
    set_error_handler($previous);
    error_clear_last();

    try {
        $report = (new Fa3Validator(schemas: schemaRepository($schema, schemaPath())))->validate(fixtureBytes('valid-minimal.xml'));
    } finally {
        $active = set_error_handler(null);
        restore_error_handler();
        restore_error_handler();
    }

    expect($active)->toBe($previous)
        ->and(error_get_last())->toBeNull()
        ->and($report->result())->toBe(Result::Errors)
        ->and(findingIds($report))->each->toBe('XSD-UNMAPPED')
        ->and(libxml_get_external_entity_loader())->toBeNull();
})->with([
    'unknown type' => '<xsd:schema xmlns:xsd="http://www.w3.org/2001/XMLSchema"><xsd:element name="Faktura" type="missing"/></xsd:schema>',
    'not XML' => 'not a schema',
]);

it('validates against schemas in a directory with reserved characters', function () {
    $directory = copySchemasTo(temporaryDirectory('ksef schemas #1 (50%)'));

    try {
        $report = (new Fa3Validator(schemas: new LocalSchemaRepository($directory)))->validate(fixtureBytes('valid-minimal.xml'));
    } finally {
        removeDirectory($directory);
    }

    expect(findingIds($report))->toBe([])
        ->and($report->result())->toBe(Result::Passed);
});

it('continues the pipeline after schema errors', function () {
    $context = new ValidationContext('');
    $context->invoice = new InvoiceDocument(new DOMDocument);
    $context->invoice->document->loadXML('<Faktura xmlns="'.InvoiceDocument::FA3_NAMESPACE.'"/>');

    expect(schemaStage()->process($context))->toBe(StageOutcome::Continue)
        ->and(array_map(fn (Finding $finding): string => $finding->id, $context->findings()))->toBe(['XSD-MISSING-ELEMENT']);
});

it('skips a context without a recognized invoice', function () {
    $context = new ValidationContext('<a/>');
    $context->document = new DOMDocument;
    $context->document->loadXML('<a/>');

    expect(schemaStage()->process($context))->toBe(StageOutcome::Continue)
        ->and($context->findings())->toBe([]);
});

it('reports a failed validation that left no libxml error', function () {
    $document = new class extends DOMDocument
    {
        public function schemaValidateSource(string $source, int $flags = 0): bool
        {
            return false;
        }
    };
    $document->loadXML(fixtureBytes('valid-minimal.xml'));
    $context = new ValidationContext('');
    $context->invoice = new InvoiceDocument($document);

    expect(schemaStage()->process($context))->toBe(StageOutcome::Continue)
        ->and(array_map(fn (Finding $finding): string => $finding->id, $context->findings()))->toBe(['XSD-UNMAPPED'])
        ->and($context->findings()[0]->line)->toBeNull()
        ->and($context->findings()[0]->message)->toEndWith('Opis techniczny: Walidacja schematu nie powiodła się bez komunikatu.');
});

it('adds no generic finding when libxml explained the failure', function () {
    $report = validateBytes(withInvalidLineNumbers(1));

    expect(findingIds($report))->toBe(['XSD-INTEGER-FORMAT'])
        ->and($report->findings[0]->message)->toContain('„abc”');
});

it('reports one finding for a value that libxml rejects twice', function (string $search, string $replace, string $id) {
    $report = validateBytes(str_replace($search, $replace, fixtureBytes('valid-minimal.xml')));

    expect(findingIds($report))->toBe([$id]);
})->with([
    'fraction digits and pattern' => ['<P_14_1>23.00</P_14_1>', '<P_14_1>23.001</P_14_1>', 'XSD-DECIMAL-PRECISION'],
    'empty value outside the set' => ['<P_12>23</P_12>', '<P_12></P_12>', 'XSD-EMPTY'],
]);

it('keeps two equal values of different elements on one line', function () {
    $bytes = str_replace(["\n", '<NIP>1111111111</NIP>', '<NIP>2222222222</NIP>'], ['', '<NIP>PL1</NIP>', '<NIP>PL1</NIP>'], fixtureBytes('valid-minimal.xml'));

    expect(findingIds(validateBytes($bytes)))->toBe(['XSD-NIP-FORMAT', 'XSD-NIP-FORMAT']);
});

it('keeps one finding per row for equal values on one line', function () {
    $bytes = str_replace("\n", '', withRows(2, '<P_11>100.00</P_11>', '<P_11>100.001</P_11>'));
    $schemaIds = array_values(array_filter(findingIds(validateBytes($bytes)), fn (string $id): bool => str_starts_with($id, 'XSD-')));

    expect($schemaIds)->toBe(['XSD-DECIMAL-PRECISION', 'XSD-DECIMAL-PRECISION']);
});

it('counts schema errors for the limit after removing duplicates', function (int $rows, array $counts) {
    $report = validateBytes(withRows($rows, '<P_11>100.00</P_11>', '<P_11>100.001</P_11>'));
    $schemaIds = array_filter(findingIds($report), fn (string $id): bool => str_starts_with($id, 'XSD-'));

    expect(array_count_values($schemaIds))->toBe($counts);

    if (isset($counts['XSD-TRUNCATED'])) {
        expect($report->findings[100]->message)->toBe("Pokazano 100 z {$rows} niezgodności ze wzorem FA(3).");
    }
})->with([
    '100 rows' => [100, ['XSD-DECIMAL-PRECISION' => 100]],
    '101 rows' => [101, ['XSD-DECIMAL-PRECISION' => 100, 'XSD-TRUNCATED' => 1]],
]);

it('explains a schema error with the hint for its field', function () {
    $report = validateBytes(str_replace('<P_15>123.00</P_15>', '<P_15>123,00</P_15>', fixtureBytes('valid-minimal.xml')));
    $finding = $report->findings[0];

    expect($finding->id)->toBe('XSD-DECIMAL-FORMAT')
        ->and($finding->field)->toBe('Fa/P_15')
        ->and($finding->line)->toBe(39)
        ->and($finding->message)->toBe('Pole kwota należności ogółem ma wartość „123,00”, która nie jest poprawną liczbą.')
        ->and($finding->fix)->toBe('Zamień przecinek na kropkę, na przykład 123.45 zamiast 123,45.')
        ->and($finding->docsUrl)->toBe('/ksef/bledy/zly-format-liczby');
});
