<?php

declare(strict_types=1);

namespace KsefFa3\Amount;

final readonly class LineValueRule implements AmountRule
{
    public function __construct(private string $id, private string $price, private string $value) {}

    public static function net(): self
    {
        return new self('AMT-LINE-NET', 'P_9A', 'P_11');
    }

    public static function gross(): self
    {
        return new self('AMT-LINE-GROSS', 'P_9B', 'P_11A');
    }

    public function check(InvoiceAmounts $amounts): iterable
    {
        foreach ($amounts->lines as $line) {
            $quantity = $line->value('P_8B');
            $price = $line->value($this->price);
            $value = $line->field($this->value);

            if ($quantity === null || $price === null || $value === null) {
                continue;
            }

            $discount = $line->value('P_10') ?? Decimal::zero();
            // The discount may be given per unit or for the whole row.
            $perRow = Decimal::round($quantity->mul($price)->sub($discount));
            $perUnit = Decimal::round($quantity->mul($price->sub($discount)));

            if ($value->value->compare($perRow) !== 0 && $value->value->compare($perUnit) !== 0) {
                yield $amounts->violationAt($this->id, $value, ['expected' => Decimal::format($perRow)]);
            }
        }
    }
}
