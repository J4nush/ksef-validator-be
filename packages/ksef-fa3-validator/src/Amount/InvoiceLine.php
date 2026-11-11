<?php

declare(strict_types=1);

namespace KsefFa3\Amount;

use BcMath\Number;
use DOMElement;
use KsefFa3\Xml\InvoiceDocument;

final readonly class InvoiceLine
{
    private const array AMOUNTS = ['P_8B', 'P_9A', 'P_9B', 'P_10', 'P_11', 'P_11A', 'P_11Vat', 'KursWaluty'];

    /**
     * @param  array<string, ?AmountField>  $amounts
     */
    public function __construct(
        public ?string $rate,
        public int $rateLine,
        public bool $oss,
        public bool $beforeCorrection,
        private array $amounts,
    ) {}

    public static function read(DOMElement $row): self
    {
        $rate = null;
        $rateLine = $row->getLineNo();
        $oss = false;
        $beforeCorrection = false;
        $amounts = [];

        for ($child = $row->firstElementChild; $child !== null; $child = $child->nextElementSibling) {
            if ($child->namespaceURI !== InvoiceDocument::FA3_NAMESPACE) {
                continue;
            }

            $name = (string) $child->localName;

            if ($name === 'P_12') {
                $rate = trim($child->textContent);
                $rateLine = $child->getLineNo();
            } elseif ($name === 'P_12_XII') {
                $oss = true;
            } elseif ($name === 'StanPrzed') {
                $beforeCorrection = true;
            } elseif (in_array($name, self::AMOUNTS, true)) {
                $amounts[$name] = AmountField::of($child);
            }
        }

        return new self($rate, $rateLine, $oss, $beforeCorrection, $amounts);
    }

    public function field(string $name): ?AmountField
    {
        return $this->amounts[$name] ?? null;
    }

    public function value(string $name): ?Number
    {
        return $this->field($name)?->value;
    }
}
