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

final readonly class ParseStage implements Stage
{
    public function __construct(private FindingFactory $findings, private string $allowedDirectory) {}

    public function process(ValidationContext $context): StageOutcome
    {
        $document = new DOMDocument;
        [$loaded, $errors] = LibxmlGuard::run(
            fn (): bool => $document->loadXML($context->bytes, LIBXML_NONET | LIBXML_BIGLINES),
            $this->allowedDirectory,
        );

        $firstError = self::firstError($errors);

        if (! $loaded || $firstError !== null) {
            $context->add($this->findings->create('XML-MALFORMED', line: $firstError?->line));

            return StageOutcome::Stop;
        }

        $context->document = $document;

        return StageOutcome::Continue;
    }

    /**
     * @param  list<LibXMLError>  $errors
     */
    private static function firstError(array $errors): ?LibXMLError
    {
        foreach ($errors as $error) {
            if ($error->level !== LIBXML_ERR_WARNING) {
                return $error;
            }
        }

        return null;
    }
}
