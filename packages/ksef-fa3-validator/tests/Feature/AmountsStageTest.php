<?php

declare(strict_types=1);

use KsefFa3\Amount\AmountField;
use KsefFa3\Amount\AmountsScope;
use KsefFa3\Amount\CurrencyVatRule;
use KsefFa3\Amount\Decimal;
use KsefFa3\Amount\InvoiceAmounts;
use KsefFa3\Amount\InvoiceLine;
use KsefFa3\Amount\LineValueRule;
use KsefFa3\Amount\PlnCurrencyFieldsRule;
use KsefFa3\Amount\RateGroups;
use KsefFa3\Amount\RateMissingRule;
use KsefFa3\Amount\RateNetRule;
use KsefFa3\Amount\RateVatRule;
use KsefFa3\Amount\TotalRule;
use KsefFa3\Catalog\FieldLabels;
use KsefFa3\Clock\FixedClock;
use KsefFa3\Fa3Validator;
use KsefFa3\Pipeline\StageOutcome;
use KsefFa3\Pipeline\ValidationContext;
use KsefFa3\Report\Finding;
use KsefFa3\Report\Layer;
use KsefFa3\Report\Report;
use KsefFa3\Report\Severity;
use KsefFa3\Report\SkippedCheck;
use KsefFa3\Stage\AmountsStage;

covers(
    AmountsStage::class,
    AmountsScope::class,
    AmountField::class,
    InvoiceAmounts::class,
    InvoiceLine::class,
    Decimal::class,
    RateGroups::class,
    LineValueRule::class,
    RateNetRule::class,
    RateMissingRule::class,
    RateVatRule::class,
    TotalRule::class,
    CurrencyVatRule::class,
    PlnCurrencyFieldsRule::class,
);

const AMOUNT_TOTALS_ORDER = [
    'P_13_1', 'P_14_1', 'P_14_1W', 'P_13_2', 'P_14_2', 'P_14_2W', 'P_13_3', 'P_14_3', 'P_14_3W', 'P_13_4', 'P_14_4', 'P_14_4W',
    'P_13_5', 'P_14_5', 'P_13_6_1', 'P_13_6_2', 'P_13_6_3', 'P_13_7', 'P_13_8', 'P_13_9', 'P_13_10', 'P_13_11', 'P_15',
];

const AMOUNT_ROW_ORDER = ['P_8B', 'P_9A', 'P_9B', 'P_10', 'P_11', 'P_11A', 'P_11Vat', 'P_12', 'P_12_XII', 'KursWaluty', 'StanPrzed'];

const MINIMAL_TOTALS = "    <P_13_1>100.00</P_13_1>\n    <P_14_1>23.00</P_14_1>\n    <P_15>123.00</P_15>\n";

/**
 * @param  array<string, string>  $totals
 * @param  list<array<string, string>>  $rows
 */
function amountsXml(array $totals, array $rows, string $currency = 'PLN', string $kind = 'VAT'): string
{
    $minimal = fixtureBytes('valid-minimal.xml');
    $start = (int) strpos($minimal, '    <FaWiersz>');
    $rowBlock = substr($minimal, $start, (int) strpos($minimal, '</FaWiersz>') + strlen("</FaWiersz>\n") - $start);

    $totalsXml = '';

    foreach (AMOUNT_TOTALS_ORDER as $name) {
        if (isset($totals[$name])) {
            $totalsXml .= "    <{$name}>{$totals[$name]}</{$name}>\n";
        }
    }

    $rowsXml = '';

    foreach ($rows as $index => $row) {
        $rowsXml .= '    <FaWiersz><NrWierszaFa>'.($index + 1).'</NrWierszaFa><P_7>Przykładowa usługa</P_7>';

        foreach (AMOUNT_ROW_ORDER as $name) {
            if (isset($row[$name])) {
                $rowsXml .= "<{$name}>{$row[$name]}</{$name}>";
            }
        }

        $rowsXml .= "</FaWiersz>\n";
    }

    return strtr($minimal, [
        MINIMAL_TOTALS => $totalsXml,
        $rowBlock => $rowsXml,
        '<KodWaluty>PLN</KodWaluty>' => "<KodWaluty>{$currency}</KodWaluty>",
        '<RodzajFaktury>VAT</RodzajFaktury>' => "<RodzajFaktury>{$kind}</RodzajFaktury>",
    ]);
}

function amountsReport(string $xml): Report
{
    set_error_handler(function (int $level, string $message): never {
        throw new ErrorException($message, 0, $level);
    });

    try {
        $report = (new Fa3Validator(FixedClock::at('2026-11-10T12:00:00Z')))->validate($xml);
    } finally {
        restore_error_handler();
    }

    $schemaErrors = array_values(array_filter(
        array_map(fn (Finding $finding): string => $finding->id.': '.$finding->message, $report->findings),
        fn (string $line): bool => str_starts_with($line, 'XSD-'),
    ));

    expect($schemaErrors)->toBe([]);

    return $report;
}

/**
 * @param  array<string, string>  $totals
 * @param  list<array<string, string>>  $rows
 * @return list<string>
 */
function amountIds(array $totals, array $rows, string $currency = 'PLN'): array
{
    $report = amountsReport(amountsXml($totals, $rows, $currency));

    expect(array_map(fn (SkippedCheck $check): string => $check->id, $report->skipped))->toBe(['KSEF-DUPLICATE']);

    return array_values(array_filter(findingIds($report), fn (string $id): bool => str_starts_with($id, 'AMT-')));
}

/**
 * @param  array<string, string>  $totals
 * @param  list<array<string, string>>  $rows
 */
function amountFinding(array $totals, array $rows, string $currency = 'PLN'): Finding
{
    $findings = array_values(array_filter(
        amountsReport(amountsXml($totals, $rows, $currency))->findings,
        fn (Finding $finding): bool => str_starts_with($finding->id, 'AMT-'),
    ));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->severity)->toBe(Severity::Warning);

    return $findings[0];
}

/**
 * @param  array<string, string>  $extra
 * @return array<string, string>
 */
function amountRow(string $quantity, string $price, string $net, string $rate = '23', array $extra = []): array
{
    return ['P_8B' => $quantity, 'P_9A' => $price, 'P_11' => $net, 'P_12' => $rate, ...$extra];
}

/**
 * @return array<string, string>
 */
function stringMap(mixed $data): array
{
    assert(is_array($data));
    $map = [];

    foreach ($data as $key => $value) {
        assert(is_string($value));
        $map[(string) $key] = $value;
    }

    return $map;
}

/**
 * @return list<array<string, string>>
 */
function stringRows(mixed $rows): array
{
    assert(is_array($rows));

    return array_values(array_map(stringMap(...), $rows));
}

function amountsStage(): AmountsStage
{
    $root = dirname(__DIR__, 2);

    return new AmountsStage(findingFactory(), FieldLabels::fromFile($root.'/resources/fields/labels.yaml'));
}

function stageContext(string $xml, string ...$findingIds): ValidationContext
{
    $context = new ValidationContext($xml);
    $context->invoice = ruleDocument($xml);
    $factory = findingFactory();

    foreach ($findingIds as $id) {
        $context->add($factory->create(str_starts_with($id, 'XSD-') ? Layer::Schema : Layer::Rules, $id, params: ['detail' => 'x']));
    }

    expect(amountsStage()->process($context))->toBe(StageOutcome::Continue);

    return $context;
}

/**
 * @return list<string>
 */
function skippedMessages(ValidationContext $context): array
{
    return array_map(fn (SkippedCheck $check): string => $check->id.': '.$check->message, $context->skipped());
}

describe('scope', function () {
    it('checks a plain VAT invoice without a note', function () {
        $report = amountsReport(fixtureBytes('valid-minimal.xml'));

        expect(findingIds($report))->toBe([])
            ->and(array_map(fn (SkippedCheck $check): string => $check->id, $report->skipped))->toBe(['KSEF-DUPLICATE']);
    });

    it('skips the amounts with a reason', function (string $search, string $replace, string $reason) {
        $xml = amountsXml(['P_13_1' => '100.00', 'P_14_1' => '23.00', 'P_15' => '999.00'], [amountRow('1', '100.00', '100.00')]);
        $context = stageContext(str_replace($search, $replace, $xml));

        expect($context->findings())->toBe([])
            ->and(skippedMessages($context))->toBe(["AMT-NOT-CHECKED: Narzędzie nie sprawdziło spójności kwot. Powód: {$reason}."])
            ->and($context->skipped()[0]->params)->toBe(['reason' => $reason]);
    })->with([
        'correction' => ['<RodzajFaktury>VAT', '<RodzajFaktury>KOR', 'faktura korygująca'],
        'advance correction' => ['<RodzajFaktury>VAT', '<RodzajFaktury>KOR_ZAL', 'faktura korygująca'],
        'settlement correction' => ['<RodzajFaktury>VAT', '<RodzajFaktury>KOR_ROZ', 'faktura korygująca'],
        'advance' => ['<RodzajFaktury>VAT', '<RodzajFaktury>ZAL', 'faktura zaliczkowa'],
        'settlement' => ['<RodzajFaktury>VAT', '<RodzajFaktury>ROZ', 'faktura rozliczeniowa'],
        'simplified' => ['<RodzajFaktury>VAT', '<RodzajFaktury>UPR', 'faktura uproszczona'],
        'unknown kind' => ['<RodzajFaktury>VAT', '<RodzajFaktury>INNA', 'rodzaj faktury inny niż VAT'],
        'missing kind' => ['<RodzajFaktury>VAT</RodzajFaktury>', '', 'rodzaj faktury inny niż VAT'],
        'invoice section in another namespace' => ['<Fa>', '<Fa xmlns="urn:x">', 'rodzaj faktury inny niż VAT'],
        'margin flag' => ['<P_PMarzyN>1</P_PMarzyN>', '<P_PMarzy>1</P_PMarzy><P_PMarzy_2>1</P_PMarzy_2>', 'procedura marży'],
        'margin total' => ['<P_15>', '<P_13_11>10.00</P_13_11><P_15>', 'procedura marży'],
        'OSS total' => ['<P_15>', '<P_13_5>10.00</P_13_5><P_15>', 'procedura OSS'],
        'OSS rate in a row' => ['<P_12>23</P_12>', '<P_12_XII>19</P_12_XII>', 'procedura OSS'],
        'taxi total' => ['<P_15>', '<P_13_4>10.00</P_13_4><P_14_4>0.40</P_14_4><P_15>', 'ryczałt dla taksówek'],
        'taxi rate 4' => ['<P_12>23</P_12>', '<P_12>4</P_12>', 'ryczałt dla taksówek'],
        'taxi rate 3' => ['<P_12>23</P_12>', '<P_12>3</P_12>', 'ryczałt dla taksówek'],
        'row before a correction' => ['<P_12>23</P_12>', '<P_12>23</P_12><StanPrzed>1</StanPrzed>', 'wiersze ze stanem przed korektą'],
    ]);

    it('skips an invoice without rows', function () {
        $context = stageContext(amountsXml(['P_13_1' => '100.00', 'P_14_1' => '23.00', 'P_15' => '123.00'], []));

        expect(skippedMessages($context))->toBe(['AMT-NOT-CHECKED: Narzędzie nie sprawdziło spójności kwot. Powód: brak wierszy faktury.']);
    });

    it('asks to fix schema errors first', function (string $id) {
        $context = stageContext(amountsXml(['P_13_1' => '100.00', 'P_14_1' => '23.00', 'P_15' => '999.00'], [amountRow('1', '100.00', '100.00')], kind: 'KOR'), $id);

        expect(array_map(fn (Finding $finding): string => $finding->id, $context->findings()))->toBe([$id])
            ->and(skippedMessages($context))->toBe(['AMT-NOT-CHECKED: Narzędzie nie sprawdziło spójności kwot. Powód: najpierw popraw błędy wzoru FA(3).']);
    })->with(['XSD-UNMAPPED', 'XSD-TRUNCATED']);

    it('checks the amounts next to other errors', function () {
        $context = stageContext(amountsXml(['P_13_1' => '100.00', 'P_14_1' => '23.00', 'P_15' => '999.00'], [amountRow('1', '100.00', '100.00')]), 'TECH-NIP-CHECKSUM');

        expect(array_map(fn (Finding $finding): string => $finding->id, $context->findings()))->toBe(['TECH-NIP-CHECKSUM', 'AMT-TOTAL'])
            ->and($context->skipped())->toBe([]);
    });

    it('does nothing without a recognized invoice', function () {
        $context = new ValidationContext('<a/>');

        expect(amountsStage()->process($context))->toBe(StageOutcome::Continue)
            ->and($context->findings())->toBe([])
            ->and($context->skipped())->toBe([]);
    });

    it('reads amounts of the invoice in its own namespace only', function (string $search, string $replace) {
        $xml = str_replace($search, $replace, amountsXml(['P_13_1' => '100.00', 'P_14_1' => '23.00', 'P_15' => '124.00'], [amountRow('1', '100.00', '100.00')]));
        $context = stageContext($xml);

        expect(array_map(fn (Finding $finding): string => $finding->id, $context->findings()))->toBe(['AMT-TOTAL'])
            ->and($context->skipped())->toBe([]);
    })->with([
        'invoice section' => ['<P_15>', '<P_15 xmlns="urn:x">123.00</P_15><P_15>'],
        'row' => ['<P_11>', '<P_11 xmlns="urn:x">5.00</P_11><P_11>'],
    ]);

    it('ignores spaces around codes', function () {
        $xml = strtr(amountsXml(['P_13_1' => '100.00', 'P_14_1' => '23.00', 'P_14_1W' => '23.00', 'P_15' => '123.00'], [amountRow('1', '100.00', '100.00', ' 23 ')]), [
            '<RodzajFaktury>VAT' => "<RodzajFaktury>\n VAT ",
            '<KodWaluty>PLN' => '<KodWaluty> PLN ',
        ]);

        expect(array_map(fn (Finding $finding): string => $finding->id, stageContext($xml)->findings()))->toBe(['AMT-PLN-CURRENCY-FIELDS']);
    });
});

describe('AMT-LINE-NET', function () {
    it('accepts a row value from quantity, price and discount', function (array $row) {
        $row = stringMap($row);

        expect(amountIds(['P_13_1' => $row['P_11'], 'P_14_1' => '0.00', 'P_15' => $row['P_11']], [['P_12' => '23', ...$row]]))->not->toContain('AMT-LINE-NET');
    })->with([
        'plain' => [['P_8B' => '2', 'P_9A' => '100.00', 'P_11' => '200.00']],
        'discount per unit' => [['P_8B' => '20', 'P_9A' => '1000', 'P_10' => '100', 'P_11' => '18000.00']],
        'discount per row' => [['P_8B' => '2', 'P_9A' => '100', 'P_10' => '10', 'P_11' => '190.00']],
        'half a grosz rounds up' => [['P_8B' => '1', 'P_9A' => '2.675', 'P_11' => '2.68']],
        'below half a grosz rounds down' => [['P_8B' => '3', 'P_9A' => '0.33333333', 'P_11' => '1.00']],
        'negative half a grosz rounds away from zero' => [['P_8B' => '-1', 'P_9A' => '2.675', 'P_11' => '-2.68']],
        'six decimal quantity' => [['P_8B' => '1.234567', 'P_9A' => '10.12345678', 'P_11' => '12.50']],
        'spaces around values' => [['P_8B' => ' 2 ', 'P_9A' => ' 100.00 ', 'P_11' => '200.00']],
    ]);

    it('warns about a row value that does not match', function () {
        $finding = amountFinding(['P_13_1' => '210.00', 'P_14_1' => '48.30', 'P_15' => '258.30'], [amountRow('2', '100.00', ' 210.00 ')]);

        expect($finding->id)->toBe('AMT-LINE-NET')
            ->and($finding->field)->toBe('Fa/FaWiersz/P_11')
            ->and($finding->line)->toBe(57)
            ->and($finding->message)->toBe('Pole wartość netto wiersza ma wartość 210.00, a z ilości, ceny i opustu wychodzi 200.00.')
            ->and($finding->fix)->toContain('zaokrąglona do grosza');
    });

    it('shows the value with a discount per row', function () {
        expect(amountFinding(['P_13_1' => '195.00', 'P_14_1' => '44.85', 'P_15' => '239.85'], [amountRow('2', '100', '195.00', extra: ['P_10' => '10'])])->message)
            ->toContain('wychodzi 190.00');
    });

    it('rounds only the result', function () {
        expect(amountIds(['P_13_1' => '2.67', 'P_14_1' => '0.61', 'P_15' => '3.28'], [amountRow('1', '2.675', '2.67')]))->toBe(['AMT-LINE-NET']);
    });

    it('skips a row without quantity, price or value', function (array $row) {
        expect(amountIds(['P_13_1' => '100.00', 'P_14_1' => '23.00', 'P_15' => '123.00'], [['P_12' => '23', 'P_11' => '100.00', ...stringMap($row)]]))->toBe([]);
    })->with([
        'no quantity' => [['P_9A' => '7.00']],
        'no price' => [['P_8B' => '3']],
    ]);

    it('checks the rows after an incomplete one', function () {
        expect(amountIds(['P_13_1' => '110.00', 'P_14_1' => '25.30', 'P_15' => '135.30'], [['P_11' => '10.00', 'P_12' => '23'], amountRow('2', '40.00', '100.00')]))->toBe(['AMT-LINE-NET']);
    });

    it('names the row', function () {
        $finding = amountFinding(['P_13_1' => '300.00', 'P_14_1' => '69.00', 'P_15' => '369.00'], [amountRow('1', '100.00', '100.00'), amountRow('1', '100.00', '200.00')]);

        expect($finding->field)->toBe('Fa/FaWiersz[2]/P_11')
            ->and($finding->line)->toBe(58);
    });
});

describe('AMT-LINE-GROSS', function () {
    it('accepts a gross row value', function (array $row) {
        expect(amountIds(['P_13_1' => '100.00', 'P_14_1' => '23.00', 'P_15' => '123.00'], [['P_12' => '23', ...stringMap($row)]]))->toBe([]);
    })->with([
        'plain' => [['P_8B' => '1', 'P_9B' => '123.00', 'P_11A' => '123.00']],
        'discount per unit' => [['P_8B' => '2', 'P_9B' => '71.50', 'P_10' => '10', 'P_11A' => '123.00']],
        'discount per row' => [['P_8B' => '2', 'P_9B' => '66.50', 'P_10' => '10', 'P_11A' => '123.00']],
    ]);

    it('warns about a gross row value that does not match', function () {
        $finding = amountFinding(['P_13_1' => '100.00', 'P_14_1' => '23.00', 'P_15' => '123.00'], [['P_8B' => '1', 'P_9B' => '120.00', 'P_11A' => '123.00', 'P_12' => '23']]);

        expect($finding->id)->toBe('AMT-LINE-GROSS')
            ->and($finding->field)->toBe('Fa/FaWiersz/P_11A')
            ->and($finding->message)->toBe('Pole wartość brutto wiersza ma wartość 123.00, a z ilości, ceny i opustu wychodzi 120.00.');
    });
});

describe('AMT-RATE-NET', function () {
    it('accepts sums of net row values per rate', function () {
        expect(amountIds(
            ['P_13_1' => '300.00', 'P_14_1' => '69.00', 'P_13_2' => '50.00', 'P_14_2' => '4.00', 'P_13_7' => '10.00', 'P_15' => '433.00'],
            [amountRow('1', '100.00', '100.00'), amountRow('1', '50.00', '50.00', '8'), amountRow('1', '200.00', '200.00'), amountRow('1', '10.00', '10.00', 'zw')],
        ))->toBe([]);
    });

    it('warns about a sum that differs from the rows', function () {
        $finding = amountFinding(['P_13_1' => '110.00', 'P_14_1' => '23.00', 'P_15' => '133.00'], [amountRow('1', '100.00', '100.00')]);

        expect($finding->id)->toBe('AMT-RATE-NET')
            ->and($finding->field)->toBe('Fa/P_13_1')
            ->and($finding->line)->toBe(37)
            ->and($finding->message)->toBe('Pole suma netto ze stawką 23% lub 22% ma wartość 110.00, a z wierszy ze stawką 23% lub 22% wynika 100.00.')
            ->and($finding->fix)->toBe('Wpisz w polu suma netto ze stawką 23% lub 22% sumę wartości netto wierszy ze stawką 23% lub 22%.');
    });

    it('names each rate group', function (string $rate, string $total, string $label, ?string $tax = null, string $gross = '11.00') {
        $totals = [$total => '11.00', 'P_15' => $gross];

        if ($tax !== null) {
            $totals['P_14_'.substr($total, 5)] = $tax;
        }

        $finding = amountFinding($totals, [['P_11' => '10.00', 'P_12' => $rate]]);

        expect($finding->id)->toBe('AMT-RATE-NET')
            ->and($finding->field)->toBe('Fa/'.$total)
            ->and($finding->message)->toContain("wierszy ze stawką {$label} wynika 10.00");
    })->with([
        ['22', 'P_13_1', '23% lub 22%', '2.20', '13.20'],
        ['7', 'P_13_2', '8% lub 7%', '0.70', '11.70'],
        ['5', 'P_13_3', '5%', '0.50', '11.50'],
        ['0 KR', 'P_13_6_1', '0 KR'],
        ['0 WDT', 'P_13_6_2', '0 WDT'],
        ['0 EX', 'P_13_6_3', '0 EX'],
        ['zw', 'P_13_7', 'zw'],
        ['np I', 'P_13_8', 'np I'],
        ['np II', 'P_13_9', 'np II'],
        ['oo', 'P_13_10', 'oo'],
    ]);

    it('checks the next rate after one without a sum', function () {
        expect(amountIds(['P_13_1' => '110.00', 'P_14_1' => '23.00', 'P_15' => '133.00'], [amountRow('1', '50.00', '50.00', '8'), amountRow('1', '100.00', '100.00')]))
            ->toBe(['AMT-RATE-MISSING', 'AMT-RATE-NET']);
    });

    it('compares gross rows with the net sum and the tax together', function () {
        $rows = [['P_8B' => '1', 'P_9B' => '123.00', 'P_11A' => '123.00', 'P_12' => '23']];

        expect(amountIds(['P_13_1' => '100.00', 'P_14_1' => '23.00', 'P_15' => '123.00'], $rows))->toBe([]);

        $finding = amountFinding(['P_13_1' => '101.00', 'P_14_1' => '23.00', 'P_15' => '124.00'], $rows);

        expect($finding->id)->toBe('AMT-RATE-NET')
            ->and($finding->message)->toContain('ma wartość 101.00, a z wierszy ze stawką 23% lub 22% wynika 100.00')
            ->and($finding->fix)->toContain('sumę wartości brutto wierszy');
    });

    it('compares gross rows of a rate without tax with the net sum alone', function () {
        expect(amountIds(['P_13_7' => '50.00', 'P_15' => '50.00'], [['P_11A' => '50.00', 'P_12' => 'zw']]))->toBe([]);
    });

    it('skips a rate group it cannot sum', function (array $rows, string $sum) {
        expect(amountIds(['P_13_1' => $sum, 'P_14_1' => '0.00', 'P_15' => $sum], stringRows($rows)))->not->toContain('AMT-RATE-NET');
    })->with([
        'net and gross rows' => [[['P_11' => '10.00', 'P_12' => '23'], ['P_11A' => '12.30', 'P_12' => '23']], '99.00'],
        'row without a value' => [[['P_11' => '10.00', 'P_12' => '23'], ['P_8B' => '1', 'P_12' => '23']], '99.00'],
        'row without a rate' => [[['P_11' => '10.00', 'P_12' => '23'], ['P_11' => '5.00']], '15.00'],
    ]);
});

describe('AMT-RATE-MISSING', function () {
    it('warns about rows of a rate without its sum', function () {
        $finding = amountFinding(['P_13_1' => '100.00', 'P_14_1' => '23.00', 'P_15' => '123.00'], [amountRow('1', '100.00', '100.00'), amountRow('1', '50.00', '50.00', '8')]);

        expect($finding->id)->toBe('AMT-RATE-MISSING')
            ->and($finding->field)->toBe('Fa/P_13_2')
            ->and($finding->line)->toBe(58)
            ->and($finding->message)->toBe('Pole suma netto ze stawką 8% lub 7% nie pasuje do wierszy faktury. Wierszy ze stawką 8% lub 7%: 1, wartość pola: brak.')
            ->and($finding->fix)->toBe('Dodaj pole suma netto ze stawką 8% lub 7% z sumą wartości netto wierszy ze stawką 8% lub 7%.');
    });

    it('warns about a sum of a rate without rows', function () {
        $finding = amountFinding(['P_13_1' => '100.00', 'P_14_1' => '23.00', 'P_13_7' => '40.00', 'P_15' => '163.00'], [amountRow('1', '100.00', '100.00')]);

        expect($finding->id)->toBe('AMT-RATE-MISSING')
            ->and($finding->field)->toBe('Fa/P_13_7')
            ->and($finding->line)->toBe(39)
            ->and($finding->message)->toBe('Pole suma sprzedaży zwolnionej nie pasuje do wierszy faktury. Wierszy ze stawką zw: 0, wartość pola: 40.00.')
            ->and($finding->fix)->toContain('usuń kwotę z pola suma sprzedaży zwolnionej');
    });

    it('accepts a zero sum without rows', function () {
        expect(amountIds(['P_13_1' => '100.00', 'P_14_1' => '23.00', 'P_13_2' => '0.00', 'P_14_2' => '0.00', 'P_15' => '123.00'], [amountRow('1', '100.00', '100.00')]))->toBe([]);
    });

    it('does not guess the rate of a row without one', function () {
        expect(amountIds(['P_13_1' => '100.00', 'P_14_1' => '23.00', 'P_13_7' => '40.00', 'P_15' => '163.00'], [amountRow('1', '100.00', '100.00'), ['P_11' => '40.00']]))->toBe([]);
    });
});

describe('AMT-RATE-VAT', function () {
    it('accepts every allowed way of computing the tax', function (array $totals, array $rows) {
        expect(amountIds(stringMap($totals), stringRows($rows)))->toBe([]);
    })->with([
        'tax from the net sum' => [['P_13_1' => '100.20', 'P_14_1' => '23.05', 'P_15' => '123.25'], array_fill(0, 10, amountRow('1', '10.02', '10.02'))],
        'sum of row taxes' => [['P_13_1' => '100.20', 'P_14_1' => '23.00', 'P_15' => '123.20'], array_fill(0, 10, amountRow('1', '10.02', '10.02'))],
        'sum of row tax fields' => [['P_13_1' => '100.20', 'P_14_1' => '22.90', 'P_15' => '123.10'], array_fill(0, 10, amountRow('1', '10.02', '10.02', extra: ['P_11Vat' => '2.29']))],
        'tax from gross rows' => [['P_13_1' => '81.30', 'P_14_1' => '18.70', 'P_15' => '100.00'], [['P_11A' => '50.00', 'P_12' => '23'], ['P_11A' => '50.00', 'P_12' => '23']]],
        'one grosz above' => [['P_13_1' => '100.00', 'P_14_1' => '23.01', 'P_15' => '123.01'], [amountRow('1', '100.00', '100.00')]],
        'one grosz below' => [['P_13_1' => '100.00', 'P_14_1' => '22.99', 'P_15' => '122.99'], [amountRow('1', '100.00', '100.00')]],
        'rate 8' => [['P_13_2' => '100.00', 'P_14_2' => '8.00', 'P_15' => '108.00'], [amountRow('1', '100.00', '100.00', '8')]],
        'rate 5' => [['P_13_3' => '100.00', 'P_14_3' => '5.00', 'P_15' => '105.00'], [amountRow('1', '100.00', '100.00', '5')]],
        'rows without one method' => [['P_13_1' => '100.00', 'P_14_1' => '23.00', 'P_15' => '123.00'], [['P_11A' => '61.50', 'P_12' => '23'], ['P_11' => '50.00', 'P_12' => '23']]],
        'mixed 23 and 22' => [['P_13_1' => '200.00', 'P_14_1' => '30.00', 'P_15' => '230.00'], [amountRow('1', '100.00', '100.00'), amountRow('1', '100.00', '100.00', '22')]],
    ]);

    it('warns about a tax that matches no method', function () {
        $finding = amountFinding(['P_13_1' => '100.00', 'P_14_1' => '22.00', 'P_15' => '122.00'], [amountRow('1', '100.00', '100.00')]);

        expect($finding->id)->toBe('AMT-RATE-VAT')
            ->and($finding->field)->toBe('Fa/P_14_1')
            ->and($finding->line)->toBe(38)
            ->and($finding->message)->toBe('Pole podatek ze stawką 23% lub 22% ma wartość 22.00, a podatek 23% od kwoty 100.00 wynosi 23.00.');
    });

    it('warns two grosze away', function (string $tax) {
        expect(amountIds(['P_13_1' => '100.00', 'P_14_1' => $tax, 'P_15' => '123.00'], [amountRow('1', '100.00', '100.00')]))->toBe(['AMT-RATE-VAT', 'AMT-TOTAL']);
    })->with(['23.02', '22.98']);

    it('checks every rate group', function (array $rows) {
        $totals = ['P_13_1' => '200.00', 'P_14_1' => '46.00', 'P_13_2' => '100.00', 'P_14_2' => '9.00', 'P_15' => '355.00'];

        expect(amountIds($totals, [...stringRows($rows), amountRow('1', '100.00', '100.00', '8')]))->toBe(['AMT-RATE-VAT']);
    })->with([
        'after a group that matches' => [[amountRow('1', '100.00', '100.00'), amountRow('1', '100.00', '100.00')]],
        'after a mixed group' => [[amountRow('1', '100.00', '100.00'), amountRow('1', '100.00', '100.00', '22')]],
    ]);

    it('warns with several rows of one rate', function () {
        expect(amountIds(['P_13_1' => '200.00', 'P_14_1' => '40.00', 'P_15' => '240.00'], [amountRow('1', '100.00', '100.00'), amountRow('1', '100.00', '100.00')]))->toBe(['AMT-RATE-VAT']);
    });

    it('uses the rate of the rows', function () {
        expect(amountFinding(['P_13_1' => '100.00', 'P_14_1' => '23.00', 'P_15' => '123.00'], [amountRow('1', '100.00', '100.00', '22')])->message)
            ->toContain('podatek 22% od kwoty 100.00 wynosi 22.00');
    });
});

describe('AMT-TOTAL', function () {
    it('accepts the total within one grosz', function (string $total) {
        expect(amountIds(['P_13_1' => '100.00', 'P_14_1' => '23.00', 'P_13_7' => '10.00', 'P_15' => $total], [amountRow('1', '100.00', '100.00'), amountRow('1', '10.00', '10.00', 'zw')]))->toBe([]);
    })->with(['133.00', '133.01', '132.99']);

    it('warns about a total that does not match', function () {
        $finding = amountFinding(['P_13_1' => '100.00', 'P_14_1' => '23.00', 'P_15' => '124.00'], [amountRow('1', '100.00', '100.00')]);

        expect($finding->id)->toBe('AMT-TOTAL')
            ->and($finding->field)->toBe('Fa/P_15')
            ->and($finding->line)->toBe(39)
            ->and($finding->message)->toBe('Pole kwota należności ogółem ma wartość 124.00, a suma kwot netto i podatku ze wszystkich stawek wynosi 123.00.');
    });

    it('warns two grosze away', function (string $total) {
        expect(amountIds(['P_13_1' => '100.00', 'P_14_1' => '23.00', 'P_15' => $total], [amountRow('1', '100.00', '100.00')]))->toBe(['AMT-TOTAL']);
    })->with(['123.02', '122.98']);

    it('leaves out the tax in złoty', function () {
        expect(amountIds(['P_13_1' => '100.00', 'P_14_1' => '23.00', 'P_14_1W' => '97.75', 'P_15' => '123.00'], [amountRow('1', '100.00', '100.00', extra: ['KursWaluty' => '4.25'])], 'EUR'))->toBe([]);
    });
});

describe('AMT-CURRENCY-VAT', function () {
    it('accepts the tax in złoty at the rate from the file', function (string $tax) {
        $rows = [amountRow('1', '60.00', '60.00', extra: ['KursWaluty' => '4.250000']), amountRow('1', '40.00', '40.00', extra: ['KursWaluty' => '4.25'])];

        expect(amountIds(['P_13_1' => '100.00', 'P_14_1' => '23.00', 'P_14_1W' => $tax, 'P_15' => '123.00'], $rows, 'EUR'))->toBe([]);
    })->with(['97.75', '97.76', '97.74']);

    it('warns about a tax in złoty that does not match the rate', function () {
        $rows = [amountRow('1', '60.00', '60.00', extra: ['KursWaluty' => '4.250000']), amountRow('1', '40.00', '40.00', extra: ['KursWaluty' => '4.25'])];
        $finding = amountFinding(['P_13_1' => '100.00', 'P_14_1' => '23.00', 'P_14_1W' => '98.00', 'P_15' => '123.00'], $rows, 'EUR');

        expect($finding->id)->toBe('AMT-CURRENCY-VAT')
            ->and($finding->field)->toBe('Fa/P_14_1W')
            ->and($finding->message)->toBe('Pole podatek w złotych ze stawką 23% lub 22% ma wartość 98.00, a podatek 23.00 przeliczony po kursie 4.250000 z pliku daje 97.75.');
    });

    it('skips the check without one rate for the whole invoice', function (array $rates) {
        $rows = array_values(array_map(fn (mixed $rate): array => amountRow('1', '50.00', '50.00', extra: is_string($rate) ? ['KursWaluty' => $rate] : []), $rates));

        expect(amountIds(['P_13_1' => '100.00', 'P_14_1' => '23.00', 'P_14_1W' => '1.00', 'P_15' => '123.00'], $rows, 'EUR'))->toBe([]);
    })->with([
        'different rates' => [['4.25', '4.30']],
        'lower rate later' => [['4.30', '4.25']],
        'row without a rate' => [['4.25', null]],
        'no rate at all' => [[null, null]],
    ]);
});

describe('AMT-CURRENCY-VAT groups', function () {
    it('checks a rate group after one without the tax in złoty', function () {
        $totals = ['P_13_1' => '100.00', 'P_14_1' => '23.00', 'P_13_2' => '100.00', 'P_14_2' => '8.00', 'P_14_2W' => '30.00', 'P_15' => '231.00'];
        $rows = [amountRow('1', '100.00', '100.00', extra: ['KursWaluty' => '4.25']), amountRow('1', '100.00', '100.00', '8', ['KursWaluty' => '4.25'])];

        expect(amountIds($totals, $rows, 'EUR'))->toBe(['AMT-CURRENCY-VAT']);
    });

    it('leaves an invoice in złoty to the other rule', function () {
        expect(amountIds(['P_13_1' => '100.00', 'P_14_1' => '23.00', 'P_14_1W' => '23.00', 'P_15' => '123.00'], [amountRow('1', '100.00', '100.00', extra: ['KursWaluty' => '4.25'])]))
            ->toBe(['AMT-PLN-CURRENCY-FIELDS']);
    });
});

describe('AMT-PLN-CURRENCY-FIELDS', function () {
    it('warns about a tax in złoty on an invoice in złoty', function () {
        $finding = amountFinding(['P_13_1' => '100.00', 'P_14_1' => '23.00', 'P_14_1W' => '23.00', 'P_15' => '123.00'], [amountRow('1', '100.00', '100.00')]);

        expect($finding->id)->toBe('AMT-PLN-CURRENCY-FIELDS')
            ->and($finding->field)->toBe('Fa/P_14_1W')
            ->and($finding->message)->toBe('Pole podatek w złotych ze stawką 23% lub 22% jest wypełnione, a faktura jest wystawiona w złotych.');
    });

    it('warns about every such field', function () {
        $ids = amountIds(
            ['P_13_1' => '100.00', 'P_14_1' => '23.00', 'P_14_1W' => '23.00', 'P_13_2' => '100.00', 'P_14_2' => '8.00', 'P_14_2W' => '8.00', 'P_13_3' => '100.00', 'P_14_3' => '5.00', 'P_14_3W' => '5.00', 'P_15' => '336.00'],
            [amountRow('1', '100.00', '100.00'), amountRow('1', '100.00', '100.00', '8'), amountRow('1', '100.00', '100.00', '5')],
        );

        expect($ids)->toBe(['AMT-PLN-CURRENCY-FIELDS', 'AMT-PLN-CURRENCY-FIELDS', 'AMT-PLN-CURRENCY-FIELDS']);
    });
});

it('shows at most 100 notes per rule', function () {
    $rows = array_fill(0, 150, amountRow('1', '1.00', '2.00'));
    $report = amountsReport(amountsXml(['P_13_1' => '300.00', 'P_14_1' => '69.00', 'P_15' => '369.00'], $rows));
    $truncated = array_values(array_filter($report->findings, fn (Finding $finding): bool => $finding->id === 'RULE-TRUNCATED'));

    expect(array_count_values(findingIds($report)))->toBe(['AMT-LINE-NET' => 100, 'RULE-TRUNCATED' => 1])
        ->and($truncated[0]->message)->toBe('Pokazano 100 z 150 uwag AMT-LINE-NET.');
});

it('parses and rounds decimals', function (string $text, ?string $parsed, ?string $rounded) {
    $value = Decimal::parse($text);

    expect($value === null ? null : (string) $value)->toBe($parsed)
        ->and($value === null ? null : Decimal::format($value))->toBe($rounded);
})->with([
    ['12.345', '12.345', '12.35'],
    ['-12.345', '-12.345', '-12.35'],
    ['12.344999', '12.344999', '12.34'],
    [' 7 ', '7', '7.00'],
    ['+1.5', '1.5', '1.50'],
    ['.5', '0.5', '0.50'],
    ['5.', '5', '5.00'],
    ['', null, null],
    ['1,5', null, null],
    ['1e3', null, null],
    ['1E3', null, null],
    ['abc', null, null],
]);

it('compares decimals within one grosz', function (string $a, string $b, bool $close) {
    expect(Decimal::withinOneGrosz(Decimal::parse($a) ?? Decimal::zero(), Decimal::parse($b) ?? Decimal::zero()))->toBe($close);
})->with([
    ['1.00', '1.01', true],
    ['1.01', '1.00', true],
    ['1.00', '1.02', false],
    ['1.02', '1.00', false],
    ['-1.00', '-1.01', true],
]);

it('reads the rest of a row after an element in another namespace', function () {
    $xml = amountsXml(['P_13_1' => '210.00', 'P_14_1' => '48.30', 'P_15' => '258.30'], [amountRow('2', '100.00', '210.00')]);
    $context = stageContext(str_replace('<P_7>', '<x:Uwaga xmlns:x="urn:x">tekst</x:Uwaga><P_7>', $xml));

    expect(array_map(fn (Finding $finding): string => $finding->id, $context->findings()))->toBe(['AMT-LINE-NET']);
});

it('leaves the tax of a rate alone when the tax field is missing', function () {
    $context = stageContext(amountsXml(['P_13_1' => '100.00', 'P_15' => '100.00'], [amountRow('1', '100.00', '100.00')]));

    expect($context->findings())->toBe([]);
});
