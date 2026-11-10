<?php

declare(strict_types=1);

use KsefFa3\Rule\LineNumberDuplicateRule;

covers(LineNumberDuplicateRule::class);

/**
 * @param  list<string>  $numbers
 */
function invoiceLines(array $numbers): string
{
    $rows = array_map(fn (string $number): string => "\n    <FaWiersz><NrWierszaFa>{$number}</NrWierszaFa></FaWiersz>", $numbers);

    return '<Faktura xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/">'."\n  <Fa>".implode('', $rows)."\n  </Fa>\n</Faktura>";
}

it('reports each repeated line number once, at its second occurrence', function () {
    $violations = violationsOf(new LineNumberDuplicateRule, invoiceLines(['1', '2', '2', '3', '3', '2']));

    expect(violationSummary($violations))->toBe([
        ['TECH-LINE-NUMBER-DUPLICATE', 'Fa/FaWiersz[3]/NrWierszaFa', 5],
        ['TECH-LINE-NUMBER-DUPLICATE', 'Fa/FaWiersz[5]/NrWierszaFa', 7],
    ])->and($violations[0]->params)->toBe([]);
});

it('compares trimmed line numbers', function () {
    expect(violationSummary(violationsOf(new LineNumberDuplicateRule, invoiceLines(['1', ' 1 ']))))
        ->toBe([['TECH-LINE-NUMBER-DUPLICATE', 'Fa/FaWiersz[2]/NrWierszaFa', 4]]);
});

it('compares line numbers by value', function (string $numbers, int $row) {
    expect(violationSummary(violationsOf(new LineNumberDuplicateRule, invoiceLines(explode(',', $numbers)))))
        ->toBe([['TECH-LINE-NUMBER-DUPLICATE', "Fa/FaWiersz[{$row}]/NrWierszaFa", $row + 2]]);
})->with([
    '1 and 01' => ['1,01', 2],
    '1 and +1' => ['1,+1', 2],
    '01 and +001' => ['2,01,+001', 3],
    'zero forms' => ['0,000,1', 2],
    'plus zero' => ['+0,0', 2],
    'zeros inside the number kept' => ['10,1,010', 3],
]);

it('skips line numbers that are not non-negative integers', function () {
    $numbers = ['x', 'x', '-1', '-1', '1.0', '1.0', '++1', '++1', '1 1', '1 1', '+', '+', "\u{0661}", "\u{0661}", '1x', '1x'];

    expect(violationsOf(new LineNumberDuplicateRule, invoiceLines($numbers)))->toBe([]);
});

it('accepts unique line numbers', function () {
    expect(violationsOf(new LineNumberDuplicateRule, invoiceLines(['1', '2', '3'])))->toBe([]);
});

it('skips empty line numbers', function () {
    expect(violationSummary(violationsOf(new LineNumberDuplicateRule, invoiceLines(['', ' ', '1', '1']))))
        ->toBe([['TECH-LINE-NUMBER-DUPLICATE', 'Fa/FaWiersz[4]/NrWierszaFa', 6]]);
});

it('ignores line numbers outside invoice rows', function () {
    $xml = fa3Xml('<NrWierszaFa>1</NrWierszaFa><Fa><NrWierszaFa>1</NrWierszaFa><FaWiersz><NrWierszaFa>1</NrWierszaFa></FaWiersz></Fa>');

    expect(violationsOf(new LineNumberDuplicateRule, $xml))->toBe([]);
});

/**
 * @param  list<array{string, ?string}>  $rows
 */
function correctionLines(array $rows): string
{
    $xml = array_map(
        fn (array $row): string => "\n    <FaWiersz><NrWierszaFa>{$row[0]}</NrWierszaFa>".($row[1] === null ? '' : "<StanPrzed>{$row[1]}</StanPrzed>").'</FaWiersz>',
        $rows,
    );

    return '<Faktura xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/">'."\n  <Fa>".implode('', $xml)."\n  </Fa>\n</Faktura>";
}

it('accepts a row before correction sharing its number with the corrected row', function () {
    expect(violationsOf(new LineNumberDuplicateRule, correctionLines([['1', '1'], ['1', null], ['2', null], ['2', ' 1 ']])))->toBe([]);
});

it('reports repeated numbers among rows after correction', function () {
    expect(violationSummary(violationsOf(new LineNumberDuplicateRule, correctionLines([['1', '1'], ['1', null], ['1', null]]))))
        ->toBe([['TECH-LINE-NUMBER-DUPLICATE', 'Fa/FaWiersz[3]/NrWierszaFa', 5]]);
});

it('reports repeated numbers among rows before correction', function () {
    expect(violationSummary(violationsOf(new LineNumberDuplicateRule, correctionLines([['1', '1'], ['1', null], ['1', '1'], ['1', '1']]))))
        ->toBe([['TECH-LINE-NUMBER-DUPLICATE', 'Fa/FaWiersz[3]/NrWierszaFa', 5]]);
});

it('treats every lexical form of the value one as a row before correction', function (string $value) {
    expect(violationsOf(new LineNumberDuplicateRule, correctionLines([['1', $value], ['1', null]])))->toBe([]);
})->with(['1', ' 1 ', '01', '+1', "\n1\t"]);

it('treats other before-correction markers as rows after correction', function (string $value) {
    expect(violationSummary(violationsOf(new LineNumberDuplicateRule, correctionLines([['1', $value], ['1', null]]))))
        ->toBe([['TECH-LINE-NUMBER-DUPLICATE', 'Fa/FaWiersz[2]/NrWierszaFa', 4]]);
})->with(['', '2', '10', '-1', '1 1', 'x1', '1x']);

it('ignores a before-correction marker outside the row itself', function () {
    $xml = fa3Xml('<Fa><StanPrzed>1</StanPrzed><FaWiersz><NrWierszaFa>1</NrWierszaFa><Zamowienie><StanPrzed>1</StanPrzed></Zamowienie></FaWiersz><FaWiersz><NrWierszaFa>1</NrWierszaFa></FaWiersz></Fa>');

    expect(violationSummary(violationsOf(new LineNumberDuplicateRule, $xml)))
        ->toBe([['TECH-LINE-NUMBER-DUPLICATE', 'Fa/FaWiersz[2]/NrWierszaFa', 1]]);
});

it('ignores a document without invoice rows', function () {
    expect(violationsOf(new LineNumberDuplicateRule, fa3Xml('<Naglowek/>')))->toBe([]);
});
