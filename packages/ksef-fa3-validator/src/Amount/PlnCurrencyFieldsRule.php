<?php

declare(strict_types=1);

namespace KsefFa3\Amount;

final readonly class PlnCurrencyFieldsRule implements AmountRule
{
    public function check(InvoiceAmounts $amounts): iterable
    {
        if ($amounts->currency !== 'PLN') {
            return;
        }

        foreach ($amounts->totals as $name => $field) {
            if (preg_match('/^P_14_\dW$/D', $name) === 1) {
                yield $amounts->violationAt('AMT-PLN-CURRENCY-FIELDS', $field);
            }
        }
    }
}
