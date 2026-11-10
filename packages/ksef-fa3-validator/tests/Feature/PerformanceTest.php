<?php

declare(strict_types=1);

use KsefFa3\Clock\FixedClock;
use KsefFa3\Fa3Validator;
use KsefFa3\Report\Result;

covers(Fa3Validator::class);

const LARGE_INVOICE_BYTES = 2_999_000;

function largeInvoiceWithAttachment(int $size): string
{
    $metadata = '<MetaDane><ZKlucz>Pozycja</ZKlucz><ZWartosc>'.str_repeat('Przykładowa wartość ', 10).'</ZWartosc></MetaDane>';
    $block = '<BlokDanych>'.str_repeat($metadata, 1000).'</BlokDanych>';
    $closing = "</Zalacznik>\n</Faktura>\n";
    $invoice = str_replace("</Faktura>\n", '', fixtureBytes('valid-full.xml')).'<Zalacznik>';

    while (strlen($invoice) + strlen($block) + strlen($closing) <= $size) {
        $invoice .= $block;
    }

    $invoice .= '<BlokDanych>';

    while (strlen($invoice) + strlen($metadata) + strlen('</BlokDanych>') + strlen($closing) <= $size) {
        $invoice .= $metadata;
    }

    $invoice .= '</BlokDanych>';

    return $invoice.str_repeat(' ', $size - strlen($invoice) - strlen($closing)).$closing;
}

it('validates an invoice just under the size limit quickly and within memory', function () {
    $bytes = largeInvoiceWithAttachment(LARGE_INVOICE_BYTES);
    $validator = new Fa3Validator(FixedClock::at('2026-11-10T12:00:00Z'));
    memory_reset_peak_usage();
    $baseline = memory_get_peak_usage(true);

    $started = hrtime(true);
    $report = $validator->validate($bytes);
    $seconds = (hrtime(true) - $started) / 1e9;
    $growth = memory_get_peak_usage(true) - $baseline;

    expect(strlen($bytes))->toBe(LARGE_INVOICE_BYTES)
        ->and($report->result())->toBe(Result::Warnings)
        ->and(findingIds($report))->toBe(['TECH-ATTACHMENT-BATCH-ONLY'])
        ->and($seconds)->toBeLessThan(3.0)
        ->and($growth)->toBeLessThan(128 * 1024 * 1024);
});

function invoiceWithRepeatedLineNumbers(int $rows): string
{
    $minimal = fixtureBytes('valid-minimal.xml');
    $start = (int) strpos($minimal, "\n    <FaWiersz>");
    $row = substr($minimal, $start, (int) strpos($minimal, '</FaWiersz>') + strlen('</FaWiersz>') - $start);
    $rowsXml = '';

    for ($index = 0; $index < $rows; $index++) {
        $rowsXml .= "\n    <FaWiersz><NrWierszaFa>".intdiv($index, 2).'</NrWierszaFa></FaWiersz>';
    }

    return str_replace($row, $rowsXml, $minimal);
}

it('reports thousands of repeated line numbers quickly', function () {
    $bytes = invoiceWithRepeatedLineNumbers(20_000);
    $validator = new Fa3Validator(FixedClock::at('2026-11-10T12:00:00Z'));

    $started = hrtime(true);
    $report = $validator->validate($bytes);
    $seconds = (hrtime(true) - $started) / 1e9;
    $duplicates = array_values(array_filter($report->findings, fn ($finding): bool => $finding->id === 'TECH-LINE-NUMBER-DUPLICATE'));
    $truncated = array_values(array_filter($report->findings, fn ($finding): bool => $finding->id === 'RULE-TRUNCATED'));

    expect(strlen($bytes))->toBeGreaterThan(1_000_000)->toBeLessThan(1_200_000)
        ->and($duplicates)->toHaveCount(100)
        ->and($duplicates[99]->field)->toBe('Fa/FaWiersz[200]/NrWierszaFa')
        ->and(array_map(fn ($finding): string => $finding->message, $truncated))->toBe(['Pokazano 100 z 10000 uwag TECH-LINE-NUMBER-DUPLICATE.'])
        ->and($seconds)->toBeLessThan(1.0);
});

it('reports hundreds of thousands of processing instructions quickly and within memory', function () {
    $minimal = fixtureBytes('valid-minimal.xml');
    $bytes = str_replace('<Podmiot1>', str_repeat('<?a?>', 590_000).'<Podmiot1>', $minimal);
    $validator = new Fa3Validator(FixedClock::at('2026-11-10T12:00:00Z'));
    memory_reset_peak_usage();
    $baseline = memory_get_peak_usage(true);

    $started = hrtime(true);
    $report = $validator->validate($bytes);
    $seconds = (hrtime(true) - $started) / 1e9;
    $growth = memory_get_peak_usage(true) - $baseline;

    expect(strlen($bytes))->toBeLessThan(3_000_000)
        ->and(findingIds($report))->toBe(['TECH-PROCESSING-INSTRUCTION', 'TECH-SIZE-WITHOUT-ATTACHMENT'])
        ->and($report->findings[0]->message)->toContain('liczba wystąpień: 590000')
        ->and($seconds)->toBeLessThan(3.0)
        ->and($growth)->toBeLessThan(64 * 1024 * 1024);
});

it('counts millions of discouraged characters within memory', function (string $character, int $count) {
    $bytes = '<a>'.str_repeat($character, $count).'</a>';
    memory_reset_peak_usage();
    $baseline = memory_get_peak_usage(true);

    $report = validateBytes($bytes);
    $growth = memory_get_peak_usage(true) - $baseline;

    expect(strlen($bytes))->toBe(2_999_007)
        ->and(findingIds($report))->toBe(['TECH-DISCOURAGED-CHAR', 'DOC-UNSUPPORTED'])
        ->and($report->findings[0]->message)->toContain("liczba wystąpień: {$count}")
        ->and($report->findings[0]->line)->toBe(1)
        ->and($growth)->toBeLessThan(64 * 1024 * 1024);
})->with([
    'U+0080' => ["\u{0080}", 1_499_500],
    'U+007F' => ["\x7F", 2_999_000],
]);
