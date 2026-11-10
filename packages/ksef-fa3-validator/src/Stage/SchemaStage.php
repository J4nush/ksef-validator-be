<?php

declare(strict_types=1);

namespace KsefFa3\Stage;

use DOMDocument;
use KsefFa3\Catalog\FindingFactory;
use KsefFa3\Pipeline\Stage;
use KsefFa3\Pipeline\StageOutcome;
use KsefFa3\Pipeline\ValidationContext;
use KsefFa3\Xml\LibxmlGuard;
use LibXMLError;

final readonly class SchemaStage implements Stage
{
    private const int SHOWN_ERRORS = 100;

    private const string SILENT_FAILURE = 'Walidacja schematu nie powiodła się bez komunikatu.';

    public function __construct(
        private FindingFactory $findings,
        private string $schemaSource,
        private string $schemaDirectory,
    ) {}

    public function process(ValidationContext $context): StageOutcome
    {
        $document = $context->invoice?->document;

        if ($document === null) {
            return StageOutcome::Continue;
        }

        [$valid, $messages] = LibxmlGuard::run(fn (): bool => $this->validate($document), $this->schemaDirectory);
        $errors = array_filter($messages, self::isError(...));

        if (! $valid && $errors === []) {
            $context->add($this->findings->create('XSD-UNMAPPED', params: ['detail' => self::SILENT_FAILURE]));
        }

        foreach (array_slice($errors, 0, self::SHOWN_ERRORS) as $error) {
            $context->add($this->findings->create('XSD-UNMAPPED', line: $error->line, params: ['detail' => trim($error->message)]));
        }

        if (count($errors) > self::SHOWN_ERRORS) {
            $context->add($this->findings->create('XSD-TRUNCATED', params: ['shown' => self::SHOWN_ERRORS, 'total' => count($errors)]));
        }

        return StageOutcome::Continue;
    }

    private function validate(DOMDocument $document): bool
    {
        // PHP adds an "Invalid Schema" warning on top of the libxml errors for a schema that does not compile.
        set_error_handler(static fn (): bool => true, E_WARNING);

        try {
            return $document->schemaValidateSource($this->schemaSource, LIBXML_NONET);
        } finally {
            restore_error_handler();
        }
    }

    private static function isError(LibXMLError $message): bool
    {
        return $message->level === LIBXML_ERR_ERROR || $message->level === LIBXML_ERR_FATAL;
    }
}
