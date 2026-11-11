<?php

declare(strict_types=1);

namespace KsefFa3\Amount;

final readonly class TotalRule implements AmountRule
{
    public function check(InvoiceAmounts $amounts): iterable
    {
        $total = $amounts->total('P_15');

        if ($total === null) {
            return;
        }

        $expected = Decimal::zero();

        foreach ($amounts->totals as $name => $field) {
            if (preg_match('/^(?:P_13_\d+(?:_\d)?|P_14_[1-5])$/D', $name) === 1) {
                $expected = $expected->add($field->value);
            }
        }

        if (! Decimal::withinOneGrosz($total->value, $expected)) {
            yield $amounts->violationAt('AMT-TOTAL', $total, ['expected' => Decimal::format($expected)]);
        }
    }
}
