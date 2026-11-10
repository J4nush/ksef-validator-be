<?php

declare(strict_types=1);
use KsefFa3\Fa3Validator;
use KsefFa3\Report\Finding;
use KsefFa3\Report\Report;
use KsefFa3\Rule\Rule;
use KsefFa3\Rule\Violation;
use KsefFa3\Schema\SchemaRepository;
use KsefFa3\Schema\SchemaSource;
use KsefFa3\Xml\InvoiceDocument;

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

/**
 * @return list<string>
 */
function findingIds(Report $report): array
{
    return array_map(fn (Finding $finding): string => $finding->id, $report->findings);
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
