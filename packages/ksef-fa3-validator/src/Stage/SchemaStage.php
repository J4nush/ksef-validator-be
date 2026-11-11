<?php

declare(strict_types=1);

namespace KsefFa3\Stage;

use DOMDocument;
use KsefFa3\Catalog\FindingFactory;
use KsefFa3\Pipeline\Stage;
use KsefFa3\Pipeline\StageOutcome;
use KsefFa3\Pipeline\ValidationContext;
use KsefFa3\Xml\LibxmlGuard;
use KsefFa3\Xsd\LibxmlMessage;
use KsefFa3\Xsd\XsdErrorClassifier;
use LibXMLError;

final readonly class SchemaStage implements Stage
{
    private const int SHOWN_ERRORS = 100;

    private const string SILENT_FAILURE = 'Walidacja schematu nie powiodła się bez komunikatu.';

    public function __construct(
        private FindingFactory $findings,
        private string $schemaSource,
        private string $schemaDirectory,
        private XsdErrorClassifier $classifier,
    ) {}

    public function process(ValidationContext $context): StageOutcome
    {
        $invoice = $context->invoice;

        if ($invoice === null) {
            return StageOutcome::Continue;
        }

        [$valid, $messages] = LibxmlGuard::run(fn (): bool => $this->validate($invoice->document), $this->schemaDirectory);
        $errors = self::withoutDuplicates(array_filter($messages, self::isError(...)));

        if (! $valid && $errors === []) {
            $context->add($this->findings->create('XSD-UNMAPPED', params: ['detail' => self::SILENT_FAILURE]));
        }

        foreach (array_slice($errors, 0, self::SHOWN_ERRORS) as $error) {
            $explained = $this->classifier->classify($error, $invoice);
            $context->add($this->findings->create($explained->id, $explained->field, $explained->line, $explained->params, $explained->hints));
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

    /**
     * @param  array<LibXMLError>  $errors
     * @return list<LibXMLError>
     */
    private static function withoutDuplicates(array $errors): array
    {
        $kept = [];
        $previous = null;

        foreach ($errors as $error) {
            $message = LibxmlMessage::parse($error);

            // libxml reports the precise facet first, for example fractionDigits before pattern.
            if ($previous !== null && $message->duplicates($previous)) {
                $previous = null;

                continue;
            }

            $kept[] = $error;
            $previous = $message;
        }

        return $kept;
    }

    private static function isError(LibXMLError $message): bool
    {
        return $message->level === LIBXML_ERR_ERROR || $message->level === LIBXML_ERR_FATAL;
    }
}
