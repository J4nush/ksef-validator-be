<?php

declare(strict_types=1);

namespace KsefFa3\Stage;

use DOMElement;
use KsefFa3\Catalog\FindingFactory;
use KsefFa3\Pipeline\Stage;
use KsefFa3\Pipeline\StageOutcome;
use KsefFa3\Pipeline\ValidationContext;
use KsefFa3\Xml\InvoiceDocument;

final readonly class RecognitionStage implements Stage
{
    public const string FA2_NAMESPACE = 'http://crd.gov.pl/wzor/2023/06/29/12648/';

    private const string TYPE = 'FA';

    private const string VARIANT = '3';

    private const string SCHEMA = '1-0E';

    private const string SYSTEM_CODE = 'FA (3)';

    public function __construct(private FindingFactory $findings) {}

    public function process(ValidationContext $context): StageOutcome
    {
        $root = $context->document?->documentElement;

        if ($root?->namespaceURI === self::FA2_NAMESPACE) {
            return $this->unsupported($context, 'DOC-FA2-UNSUPPORTED');
        }

        if ($context->document === null || $root?->namespaceURI !== InvoiceDocument::FA3_NAMESPACE || $root->localName !== 'Faktura') {
            return $this->unsupported($context, 'DOC-UNSUPPORTED');
        }

        $invoice = new InvoiceDocument($context->document);

        if (! self::declaresSupportedForm($invoice)) {
            return $this->unsupported($context, 'DOC-UNSUPPORTED');
        }

        $context->invoice = $invoice;
        $context->type = self::TYPE;
        $context->variant = self::VARIANT;
        $context->schema = self::SCHEMA;
        $context->skip($this->findings->skipped('KSEF-DUPLICATE'));

        return StageOutcome::Continue;
    }

    private static function declaresSupportedForm(InvoiceDocument $invoice): bool
    {
        $code = $invoice->first('/fa:Faktura/fa:Naglowek/fa:KodFormularza');
        $variant = $invoice->first('/fa:Faktura/fa:Naglowek/fa:WariantFormularza');

        return self::matches($code, self::TYPE)
            && self::attributeMatches($code, 'kodSystemowy', self::SYSTEM_CODE)
            && self::attributeMatches($code, 'wersjaSchemy', self::SCHEMA)
            && self::matches($variant, self::VARIANT);
    }

    private static function matches(?DOMElement $element, string $expected): bool
    {
        return $element === null || trim($element->textContent) === $expected;
    }

    private static function attributeMatches(?DOMElement $element, string $name, string $expected): bool
    {
        return $element === null || ! $element->hasAttribute($name) || $element->getAttribute($name) === $expected;
    }

    private function unsupported(ValidationContext $context, string $id): StageOutcome
    {
        $context->add($this->findings->create($id));
        $context->markUnsupported();

        return StageOutcome::Stop;
    }
}
