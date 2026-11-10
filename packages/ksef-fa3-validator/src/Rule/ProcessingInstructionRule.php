<?php

declare(strict_types=1);

namespace KsefFa3\Rule;

use DOMProcessingInstruction;
use DOMXPath;
use KsefFa3\Xml\InvoiceDocument;

final readonly class ProcessingInstructionRule implements Rule
{
    private const string INSTRUCTIONS = '/descendant::processing-instruction()';

    public function check(InvoiceDocument $document, string $bytes): iterable
    {
        $xpath = new DOMXPath($document->document);
        // Asking for the whole node set makes libxml sort it, which is quadratic for nodes other than elements.
        $first = ($xpath->query(self::INSTRUCTIONS.'[1]') ?: null)?->item(0);
        $count = $xpath->evaluate('count('.self::INSTRUCTIONS.')');

        if ($first instanceof DOMProcessingInstruction && is_float($count)) {
            yield new Violation('TECH-PROCESSING-INSTRUCTION', line: $first->getLineNo(), params: ['count' => (int) $count]);
        }
    }
}
