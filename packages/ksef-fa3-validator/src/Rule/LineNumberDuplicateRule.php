<?php

declare(strict_types=1);

namespace KsefFa3\Rule;

use DOMNode;
use KsefFa3\Xml\InvoiceDocument;
use SplObjectStorage;

final readonly class LineNumberDuplicateRule implements Rule
{
    public function check(InvoiceDocument $document, string $bytes): iterable
    {
        $rowsBeforeCorrection = self::rowsBeforeCorrection($document);
        $occurrences = [];

        foreach ($document->nodes('/fa:Faktura/fa:Fa/fa:FaWiersz/fa:NrWierszaFa') as $element) {
            $number = self::normalised($element->textContent);

            if ($number === null) {
                continue;
            }

            $group = $element->parentNode !== null && $rowsBeforeCorrection->offsetExists($element->parentNode) ? 'before' : 'after';
            $occurrences[$group][$number] = ($occurrences[$group][$number] ?? 0) + 1;

            if ($occurrences[$group][$number] === 2) {
                yield new Violation('TECH-LINE-NUMBER-DUPLICATE', $document->fieldPath($element), $element->getLineNo());
            }
        }
    }

    private static function normalised(string $value): ?string
    {
        $value = trim($value);

        return preg_match('/^\+?[0-9]+$/D', $value) === 1 ? (string) preg_replace('/^\+?0*(?=[0-9])/', '', $value) : null;
    }

    /**
     * @return SplObjectStorage<DOMNode, null>
     */
    private static function rowsBeforeCorrection(InvoiceDocument $document): SplObjectStorage
    {
        /** @var SplObjectStorage<DOMNode, null> $rows */
        $rows = new SplObjectStorage;

        foreach ($document->nodes('/fa:Faktura/fa:Fa/fa:FaWiersz/fa:StanPrzed') as $marker) {
            if ($marker->parentNode !== null && preg_match('/^\+?0*1$/', trim($marker->textContent)) === 1) {
                $rows->offsetSet($marker->parentNode);
            }
        }

        return $rows;
    }
}
