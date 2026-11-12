<?php

declare(strict_types=1);

namespace KsefFa3\Rule;

use KsefFa3\Value\Nip;
use KsefFa3\Xml\InvoiceDocument;

final readonly class NipChecksumRule implements Rule
{
    private const string NIP_XPATH = '/fa:Faktura/*[self::fa:Podmiot1 or self::fa:Podmiot2 or self::fa:Podmiot3 or self::fa:PodmiotUpowazniony]/fa:DaneIdentyfikacyjne/fa:NIP';

    private const string INTERNAL_ID_XPATH = '/fa:Faktura/fa:Podmiot3/fa:DaneIdentyfikacyjne/fa:IDWew';

    public function check(InvoiceDocument $document, string $bytes): iterable
    {
        foreach ($document->nodes(self::NIP_XPATH) as $element) {
            $nip = trim($element->textContent);

            if (preg_match('/^\d{10}$/D', $nip) === 1 && ! Nip::isValid($nip)) {
                yield new Violation('TECH-NIP-CHECKSUM', $document->fieldPath($element), $element->getLineNo(), self::params($nip));
            }
        }

        foreach ($document->nodes(self::INTERNAL_ID_XPATH) as $element) {
            $internalId = trim($element->textContent);

            if (preg_match('/^\d{10}-\d{5}$/D', $internalId) === 1 && ! Nip::isValid(substr($internalId, 0, 10))) {
                yield new Violation('TECH-INTERNAL-ID-CHECKSUM', $document->fieldPath($element), $element->getLineNo(), self::params($internalId));
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private static function params(string $value): array
    {
        $checkDigit = Nip::checkDigit($value);

        return $checkDigit === null ? ['value' => $value] : ['value' => $value, 'expected' => (string) $checkDigit];
    }
}
