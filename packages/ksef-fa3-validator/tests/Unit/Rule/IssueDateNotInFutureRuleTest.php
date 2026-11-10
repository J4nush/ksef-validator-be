<?php

declare(strict_types=1);

use KsefFa3\Clock\FixedClock;
use KsefFa3\Rule\IssueDateNotInFutureRule;

covers(IssueDateNotInFutureRule::class);

function issueDateXml(string $date): string
{
    return <<<XML
        <Faktura xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/">
          <Naglowek/>
          <Fa>
            <KodWaluty>PLN</KodWaluty>
            <P_1>{$date}</P_1>
          </Fa>
        </Faktura>
        XML;
}

it('compares the issue date with the current date in Poland', function (string $now, string $date, array $expected) {
    $rule = new IssueDateNotInFutureRule(FixedClock::at($now));

    expect(violationSummary(violationsOf($rule, issueDateXml($date))))->toBe($expected);
})->with([
    'winter, one second before midnight' => ['2026-11-10T22:59:59Z', '2026-11-11', [['TECH-P1-FUTURE', 'Fa/P_1', 5]]],
    'winter, midnight' => ['2026-11-10T23:00:00Z', '2026-11-11', []],
    'summer, one second before midnight' => ['2026-07-10T21:59:59Z', '2026-07-11', [['TECH-P1-FUTURE', 'Fa/P_1', 5]]],
    'summer, midnight' => ['2026-07-10T22:00:00Z', '2026-07-11', []],
    'today' => ['2026-11-10T12:00:00Z', '2026-11-10', []],
    'yesterday' => ['2026-11-10T12:00:00Z', '2026-11-09', []],
    'next year' => ['2026-11-10T12:00:00Z', '2027-01-01', [['TECH-P1-FUTURE', 'Fa/P_1', 5]]],
    'surrounding whitespace' => ['2026-11-10T12:00:00Z', ' 2026-11-11 ', [['TECH-P1-FUTURE', 'Fa/P_1', 5]]],
    'offset clock' => ['2026-11-11T00:30:00+01:00', '2026-11-11', []],
]);

it('skips an issue date that is not a calendar date', function (string $date) {
    $rule = new IssueDateNotInFutureRule(FixedClock::at('2026-11-10T12:00:00Z'));

    expect(violationsOf($rule, issueDateXml($date)))->toBe([]);
})->with([
    'impossible month and day' => '2026-13-45',
    'february 30' => '2027-02-30',
    'no padding' => '2027-1-1',
    'with time' => '2027-01-01T00:00:00',
    'text' => 'jutro',
    'empty' => '',
]);

it('ignores a document without an issue date', function (string $inner) {
    $rule = new IssueDateNotInFutureRule(FixedClock::at('2026-11-10T12:00:00Z'));

    expect(violationsOf($rule, fa3Xml($inner)))->toBe([]);
})->with([
    'no Fa' => '<Naglowek/>',
    'no P_1' => '<Naglowek/><Fa><KodWaluty>PLN</KodWaluty></Fa>',
    'P_1 outside Fa' => '<Naglowek/><P_1>2027-01-01</P_1><Fa><Zamowienie><P_1>2027-01-01</P_1></Zamowienie></Fa>',
]);
