<?php

declare(strict_types=1);

namespace KsefFa3\Amount;

use BcMath\Number;

final readonly class RateVatRule implements AmountRule
{
    private const int DIVISION_SCALE = 10;

    public function check(InvoiceAmounts $amounts): iterable
    {
        foreach (RateGroups::TAXED as $group) {
            $net = $amounts->total('P_13_'.$group);
            $tax = $amounts->total('P_14_'.$group);
            $lines = $amounts->groups[$group] ?? [];
            $rates = array_values(array_unique(array_map(fn (InvoiceLine $line): string => (string) $line->rate, $lines)));
            // A group with both 23% and 22% rows has no single rate to check against.
            $rate = count($rates) === 1 ? Decimal::parse($rates[0]) : null;

            if ($net === null || $tax === null || $rate === null) {
                continue;
            }

            $fromNet = Decimal::round($net->value->mul($rate)->div(100, self::DIVISION_SCALE));

            foreach ([$fromNet, ...self::fromLines($lines, $rate)] as $candidate) {
                if (Decimal::withinOneGrosz($tax->value, $candidate)) {
                    continue 2;
                }
            }

            yield $amounts->violationAt('AMT-RATE-VAT', $tax, ['rate' => $rate.'%', 'net' => $net->text, 'expected' => Decimal::format($fromNet)]);
        }
    }

    /**
     * @param  list<InvoiceLine>  $lines
     * @return list<Number>
     */
    private static function fromLines(array $lines, Number $rate): array
    {
        $candidates = [];
        $rowTaxes = Decimal::zero();

        foreach ($lines as $line) {
            $net = $line->value('P_11');

            if ($net === null) {
                $rowTaxes = null;
                break;
            }

            $rowTaxes = $rowTaxes->add(Decimal::round($net->mul($rate)->div(100, self::DIVISION_SCALE)));
        }

        $taxFields = InvoiceAmounts::sum($lines, 'P_11Vat');
        $gross = InvoiceAmounts::sum($lines, 'P_11A');

        if ($rowTaxes !== null) {
            $candidates[] = $rowTaxes;
        }

        if ($taxFields !== null) {
            $candidates[] = $taxFields;
        }

        if ($gross !== null) {
            $candidates[] = Decimal::round($gross->mul($rate)->div($rate->add(100), self::DIVISION_SCALE));
        }

        return $candidates;
    }
}
