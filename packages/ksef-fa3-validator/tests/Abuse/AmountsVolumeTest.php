<?php

declare(strict_types=1);

use KsefFa3\Clock\FixedClock;
use KsefFa3\Fa3Validator;
use KsefFa3\Report\Finding;
use KsefFa3\Report\SkippedCheck;
use KsefFa3\Stage\AmountsStage;

covers(AmountsStage::class);

const MOST_ROWS_ALLOWED = 10_000;

function invoiceWithRows(int $rows, string $value): string
{
    $minimal = fixtureBytes('valid-minimal.xml');
    $start = (int) strpos($minimal, "    <FaWiersz>\n");
    $row = substr($minimal, $start, (int) strpos($minimal, '</FaWiersz>') + strlen("</FaWiersz>\n") - $start);
    $rowsXml = '';

    for ($index = 1; $index <= $rows; $index++) {
        $rowsXml .= "<FaWiersz><NrWierszaFa>{$index}</NrWierszaFa><P_7>Usługa</P_7><P_8B>3</P_8B><P_9A>0.33333333</P_9A><P_11>{$value}</P_11><P_12>23</P_12></FaWiersz>\n";
    }

    $net = number_format($rows, 2, '.', '');
    $tax = number_format($rows * 0.23, 2, '.', '');
    $total = number_format($rows * 1.23, 2, '.', '');

    return strtr($minimal, [
        $row => $rowsXml,
        '<P_13_1>100.00</P_13_1>' => "<P_13_1>{$net}</P_13_1>",
        '<P_14_1>23.00</P_14_1>' => "<P_14_1>{$tax}</P_14_1>",
        '<P_15>123.00</P_15>' => "<P_15>{$total}</P_15>",
    ]);
}

it('checks the amounts of the largest invoice quickly', function (string $value, array $counts) {
    $bytes = invoiceWithRows(MOST_ROWS_ALLOWED, $value);
    $validator = new Fa3Validator(FixedClock::at('2026-11-10T12:00:00Z'));

    $started = hrtime(true);
    $report = $validator->validate($bytes);
    $seconds = (hrtime(true) - $started) / 1e9;

    expect(strlen($bytes))->toBeGreaterThan(1_000_000)->toBeLessThan(1_500_000)
        ->and(array_count_values(array_map(fn (Finding $finding): string => $finding->id, $report->findings)))->toBe($counts)
        ->and(array_map(fn (SkippedCheck $check): string => $check->id, $report->skipped))->toBe(['KSEF-DUPLICATE'])
        ->and($seconds)->toBeLessThan(1.0);
})->with([
    'consistent rows' => ['1.00', ['TECH-SIZE-WITHOUT-ATTACHMENT' => 1]],
    'every row off' => ['1.01', ['TECH-SIZE-WITHOUT-ATTACHMENT' => 1, 'AMT-LINE-NET' => 100, 'AMT-RATE-NET' => 1, 'RULE-TRUNCATED' => 1]],
]);
