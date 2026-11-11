<?php

declare(strict_types=1);

namespace KsefFa3\Amount;

final class RateGroups
{
    public const array TAXED = ['1', '2', '3'];

    public const array TAXI_RATES = ['4', '3'];

    private const array GROUPS = [
        '23' => '1', '22' => '1', '8' => '2', '7' => '2', '5' => '3', '0 KR' => '6_1', '0 WDT' => '6_2', '0 EX' => '6_3',
        'zw' => '7', 'np I' => '8', 'np II' => '9', 'oo' => '10',
    ];

    private const array LABELS = [
        '1' => '23% lub 22%', '2' => '8% lub 7%', '3' => '5%', '6_1' => '0 KR', '6_2' => '0 WDT', '6_3' => '0 EX',
        '7' => 'zw', '8' => 'np I', '9' => 'np II', '10' => 'oo',
    ];

    public static function of(?string $rate): ?string
    {
        return self::GROUPS[$rate ?? ''] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_map(strval(...), array_keys(self::LABELS));
    }

    public static function label(string $group): string
    {
        return self::LABELS[$group] ?? $group;
    }
}
