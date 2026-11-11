<?php

declare(strict_types=1);

namespace KsefFa3\Amount;

final readonly class RateNetRule implements AmountRule
{
    public function check(InvoiceAmounts $amounts): iterable
    {
        if (! $amounts->everyLineHasRate) {
            return;
        }

        foreach ($amounts->groups as $group => $lines) {
            $total = $amounts->total('P_13_'.$group);

            if ($total === null) {
                continue;
            }

            $net = InvoiceAmounts::sum($lines, 'P_11');
            $gross = InvoiceAmounts::sum($lines, 'P_11A');
            $expected = $net ?? $gross?->sub($amounts->total('P_14_'.$group)->value ?? Decimal::zero());

            if ($expected !== null && $total->value->compare($expected) !== 0) {
                yield $amounts->violationAt('AMT-RATE-NET', $total, [
                    'rate' => RateGroups::label((string) $group),
                    'expected' => Decimal::format($expected),
                ], $net === null ? ['gross'] : []);
            }
        }
    }
}
