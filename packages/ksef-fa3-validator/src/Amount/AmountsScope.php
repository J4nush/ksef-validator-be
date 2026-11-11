<?php

declare(strict_types=1);

namespace KsefFa3\Amount;

final class AmountsScope
{
    private const array KINDS = [
        'KOR' => 'faktura korygująca',
        'KOR_ZAL' => 'faktura korygująca',
        'KOR_ROZ' => 'faktura korygująca',
        'ZAL' => 'faktura zaliczkowa',
        'ROZ' => 'faktura rozliczeniowa',
        'UPR' => 'faktura uproszczona',
    ];

    private const string OTHER_KIND = 'rodzaj faktury inny niż VAT';

    public static function reasonToSkip(InvoiceAmounts $amounts): ?string
    {
        $lines = $amounts->lines;

        return match (true) {
            $amounts->kind !== 'VAT' => self::KINDS[$amounts->kind ?? ''] ?? self::OTHER_KIND,
            $amounts->margin || $amounts->hasTotal('P_13_11') => 'procedura marży',
            $amounts->hasTotal('P_13_5') || self::any($lines, fn (InvoiceLine $line): bool => $line->oss) => 'procedura OSS',
            $amounts->hasTotal('P_13_4') || self::any($lines, fn (InvoiceLine $line): bool => in_array($line->rate, RateGroups::TAXI_RATES, true)) => 'ryczałt dla taksówek',
            self::any($lines, fn (InvoiceLine $line): bool => $line->beforeCorrection) => 'wiersze ze stanem przed korektą',
            $lines === [] => 'brak wierszy faktury',
            default => null,
        };
    }

    /**
     * @param  list<InvoiceLine>  $lines
     * @param  callable(InvoiceLine): bool  $test
     */
    private static function any(array $lines, callable $test): bool
    {
        foreach ($lines as $line) {
            if ($test($line)) {
                return true;
            }
        }

        return false;
    }
}
