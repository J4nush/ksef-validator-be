<?php

declare(strict_types=1);

namespace KsefFa3\Amount;

final readonly class CurrencyVatRule implements AmountRule
{
    public function check(InvoiceAmounts $amounts): iterable
    {
        if ($amounts->currency === 'PLN') {
            return;
        }

        $rate = self::singleRate($amounts->lines);

        if ($rate === null) {
            return;
        }

        foreach (RateGroups::TAXED as $group) {
            $tax = $amounts->total('P_14_'.$group);
            $inZloty = $amounts->total('P_14_'.$group.'W');

            if ($tax === null || $inZloty === null) {
                continue;
            }

            $expected = Decimal::round($tax->value->mul($rate->value));

            if (! Decimal::withinOneGrosz($inZloty->value, $expected)) {
                yield $amounts->violationAt('AMT-CURRENCY-VAT', $inZloty, ['tax' => $tax->text, 'rate' => $rate->text, 'expected' => Decimal::format($expected)]);
            }
        }
    }

    /**
     * @param  list<InvoiceLine>  $lines
     */
    private static function singleRate(array $lines): ?AmountField
    {
        $rate = null;

        foreach ($lines as $line) {
            $lineRate = $line->field('KursWaluty');

            if ($lineRate === null || ($rate !== null && $lineRate->value->compare($rate->value) !== 0)) {
                return null;
            }

            $rate ??= $lineRate;
        }

        return $rate;
    }
}
