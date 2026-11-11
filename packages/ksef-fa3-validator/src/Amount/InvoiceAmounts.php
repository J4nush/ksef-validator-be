<?php

declare(strict_types=1);

namespace KsefFa3\Amount;

use BcMath\Number;
use KsefFa3\Catalog\FieldLabels;
use KsefFa3\Rule\Violation;
use KsefFa3\Xml\InvoiceDocument;

final readonly class InvoiceAmounts
{
    /** @var array<int|string, list<InvoiceLine>> */
    public array $groups;

    public bool $everyLineHasRate;

    /**
     * @param  array<string, AmountField>  $totals
     * @param  list<string>  $totalNames
     * @param  list<InvoiceLine>  $lines
     */
    private function __construct(
        private InvoiceDocument $document,
        private FieldLabels $labels,
        public ?string $currency,
        public ?string $kind,
        public bool $margin,
        public array $totals,
        public array $totalNames,
        public array $lines,
    ) {
        $groups = [];
        $everyLineHasRate = true;

        foreach ($lines as $line) {
            $group = RateGroups::of($line->rate);
            $everyLineHasRate = $everyLineHasRate && $line->rate !== null;

            if ($group !== null) {
                $groups[$group][] = $line;
            }
        }

        $this->groups = $groups;
        $this->everyLineHasRate = $everyLineHasRate;
    }

    public static function read(InvoiceDocument $document, FieldLabels $labels): self
    {
        $currency = null;
        $kind = null;
        $totals = [];
        $totalNames = [];
        $lines = [];
        $invoice = $document->first('/fa:Faktura/fa:Fa');

        for ($child = $invoice?->firstElementChild; $child !== null; $child = $child->nextElementSibling) {
            if ($child->namespaceURI !== InvoiceDocument::FA3_NAMESPACE) {
                continue;
            }

            $name = (string) $child->localName;

            if ($name === 'KodWaluty') {
                $currency = trim($child->textContent);
            } elseif ($name === 'RodzajFaktury') {
                $kind = trim($child->textContent);
            } elseif ($name === 'FaWiersz') {
                $lines[] = InvoiceLine::read($child);
            } elseif (preg_match('/^P_1[345](?:_|$)/', $name) === 1) {
                $totalNames[] = $name;
                $field = AmountField::of($child);

                if ($field !== null) {
                    $totals[$name] = $field;
                }
            }
        }

        $margin = $document->first('/fa:Faktura/fa:Fa/fa:Adnotacje/fa:PMarzy/fa:P_PMarzy') !== null;

        return new self($document, $labels, $currency, $kind, $margin, $totals, $totalNames, $lines);
    }

    public function total(string $name): ?AmountField
    {
        return $this->totals[$name] ?? null;
    }

    public function hasTotal(string $name): bool
    {
        return in_array($name, $this->totalNames, true);
    }

    /**
     * @param  list<InvoiceLine>  $lines
     */
    public static function sum(array $lines, string $name): ?Number
    {
        $sum = Decimal::zero();

        foreach ($lines as $line) {
            $value = $line->value($name);

            if ($value === null) {
                return null;
            }

            $sum = $sum->add($value);
        }

        return $sum;
    }

    /**
     * @param  array<string, string|int>  $params
     * @param  list<string>  $hints
     */
    public function violationAt(string $id, AmountField $field, array $params = [], array $hints = []): Violation
    {
        return $this->violation($id, $this->document->fieldPath($field->element), $field->element->getLineNo(), ['value' => $field->text, ...$params], $hints);
    }

    /**
     * @param  array<string, string|int>  $params
     * @param  list<string>  $hints
     */
    public function violation(string $id, string $field, int $line, array $params = [], array $hints = []): Violation
    {
        return new Violation($id, $field, $line, ['label' => $this->labels->for($field), ...$params], $hints);
    }
}
