<?php

declare(strict_types=1);

namespace KsefFa3\Amount;

use BcMath\Number;
use RoundingMode;

final class Decimal
{
    public static function parse(string $text): ?Number
    {
        $text = trim($text);

        return is_numeric($text) && stripos($text, 'e') === false ? new Number($text) : null;
    }

    public static function zero(): Number
    {
        return new Number('0');
    }

    public static function round(Number $value): Number
    {
        return $value->round(2, RoundingMode::HalfAwayFromZero);
    }

    public static function format(Number $value): string
    {
        return (string) self::round($value);
    }

    public static function withinOneGrosz(Number $value, Number $expected): bool
    {
        $difference = $value->sub($expected);

        return $difference->compare('0.01') <= 0 && $difference->compare('-0.01') >= 0;
    }
}
