<?php

declare(strict_types=1);

namespace KsefFa3\Amount;

final readonly class RateMissingRule implements AmountRule
{
    private const string MISSING = 'brak';

    public function check(InvoiceAmounts $amounts): iterable
    {
        foreach ($amounts->groups as $group => $lines) {
            $name = 'P_13_'.$group;

            if (! $amounts->hasTotal($name)) {
                yield $amounts->violation('AMT-RATE-MISSING', 'Fa/'.$name, $lines[0]->rateLine, [
                    'rate' => RateGroups::label((string) $group),
                    'lines' => count($lines),
                    'value' => self::MISSING,
                ], ['missing-total']);
            }
        }

        if (! $amounts->everyLineHasRate) {
            return;
        }

        foreach (RateGroups::all() as $group) {
            $total = $amounts->total('P_13_'.$group);

            if ($total !== null && ! isset($amounts->groups[$group]) && $total->value->compare(0) !== 0) {
                yield $amounts->violationAt('AMT-RATE-MISSING', $total, ['rate' => RateGroups::label($group), 'lines' => 0], ['missing-rows']);
            }
        }
    }
}
