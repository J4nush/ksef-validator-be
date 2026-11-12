<?php

declare(strict_types=1);
use KsefFa3\Catalog\FieldLabels;
use KsefFa3\Catalog\FindingFactory;
use KsefFa3\Catalog\RuleCatalog;
use KsefFa3\Clock\FixedClock;
use KsefFa3\Fa3Validator;
use KsefFa3\Pipeline\ValidationContext;
use KsefFa3\Report\Finding;
use KsefFa3\Report\Report;
use KsefFa3\Rule\Rule;
use KsefFa3\Rule\Violation;
use KsefFa3\Schema\SchemaRepository;
use KsefFa3\Schema\SchemaSource;
use KsefFa3\Xml\InvoiceDocument;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

function schemaPath(string $file = ''): string
{
    return dirname(__DIR__).'/resources/schemas/'.$file;
}

function fixtureBytes(string $file): string
{
    return (string) file_get_contents(__DIR__.'/Fixtures/'.$file);
}

function copySchemasTo(string $directory): string
{
    mkdir($directory.'/bazowe', recursive: true);

    foreach (['schemat_FA(3)_v1-0E.xsd', 'source.json', 'bazowe/StrukturyDanych_v10-0E.xsd', 'bazowe/ElementarneTypyDanych_v10-0E.xsd', 'bazowe/KodyKrajow_v10-0E.xsd'] as $file) {
        copy(schemaPath($file), $directory.'/'.$file);
    }

    return $directory;
}

function removeDirectory(string $directory): void
{
    exec('rm -rf '.escapeshellarg($directory));
}

function rulesPath(): string
{
    return dirname(__DIR__).'/resources/rules';
}

function fieldLabels(): FieldLabels
{
    return FieldLabels::fromFile(dirname(__DIR__).'/resources/fields/labels.yaml');
}

function findingFactory(?string $rulesDirectory = null): FindingFactory
{
    return new FindingFactory(RuleCatalog::fromDirectory($rulesDirectory ?? rulesPath()), fieldLabels());
}

/**
 * @return list<string>
 */
function reportSchemaErrors(Report|string $report): array
{
    $schema = json_decode((string) file_get_contents(dirname(__DIR__).'/resources/report.schema.json'), flags: JSON_THROW_ON_ERROR);
    assert(is_object($schema));

    $validator = new Validator;
    $validator->setMaxErrors(20);
    $json = $report instanceof Report ? $report->toJson() : $report;
    $result = $validator->validate(json_decode($json, flags: JSON_THROW_ON_ERROR), $schema);
    $error = $result->error();

    if ($error === null) {
        return [];
    }

    $errors = [];

    foreach ((new ErrorFormatter)->formatFlat($error) as $message) {
        $errors[] = is_string($message) ? $message : 'Unreadable schema error.';
    }

    return $errors;
}

/**
 * @return array<string, string>
 */
function layerStatuses(ValidationContext $context): array
{
    $statuses = [];

    foreach ($context->stages() as $result) {
        $statuses[$result->layer->value] = $result->status->value;
    }

    return $statuses;
}

/**
 * @return list<string>
 */
function findingIds(Report $report): array
{
    return array_map(fn (Finding $finding): string => $finding->id, $report->findings);
}

/**
 * @return array<string, string>
 */
function layersOf(Report $report): array
{
    return array_column($report->toArray()['stages'], 'status', 'id');
}

function validateAt(string $bytes): Report
{
    return (new Fa3Validator(FixedClock::at('2026-11-10T12:00:00Z')))->validate($bytes);
}

function validateBytes(string $bytes): Report
{
    return (new Fa3Validator)->validate($bytes);
}

function schemaRepository(string $source, string $directory): SchemaRepository
{
    return new readonly class($source, $directory) implements SchemaRepository
    {
        public function __construct(private string $source, private string $directory) {}

        public function fa3SchemaSource(): string
        {
            return $this->source;
        }

        public function schemaDirectory(): string
        {
            return $this->directory;
        }

        public function source(): SchemaSource
        {
            return new SchemaSource('commit', 'sha', []);
        }
    };
}

function fa3Xml(string $inner): string
{
    return '<Faktura xmlns="'.InvoiceDocument::FA3_NAMESPACE.'">'.$inner.'</Faktura>';
}

function ruleDocument(string $xml): InvoiceDocument
{
    $document = new DOMDocument;
    $document->loadXML($xml);

    return new InvoiceDocument($document);
}

/**
 * @return list<Violation>
 */
function violationsOf(Rule $rule, string $xml, ?string $bytes = null): array
{
    $document = ruleDocument($xml);
    set_error_handler(function (int $level, string $message): never {
        throw new ErrorException($message, 0, $level);
    });

    try {
        $violations = [];

        foreach ($rule->check($document, $bytes ?? $xml) as $violation) {
            $violations[] = $violation;
        }

        return $violations;
    } finally {
        restore_error_handler();
    }
}

/**
 * @param  list<Violation>  $violations
 * @return list<array{string, ?string, ?int}>
 */
function violationSummary(array $violations): array
{
    return array_map(fn (Violation $violation): array => [$violation->id, $violation->field, $violation->line], $violations);
}

function libxmlError(int $code, string $message, int $line = 7): LibXMLError
{
    $error = new LibXMLError;
    $error->level = LIBXML_ERR_ERROR;
    $error->code = $code;
    $error->message = $message."\n";
    $error->line = $line;

    return $error;
}
