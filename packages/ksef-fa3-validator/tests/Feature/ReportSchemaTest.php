<?php

declare(strict_types=1);

use KsefFa3\Report\Report;

covers(Report::class);

function badNipSellerJson(): string
{
    return validateAt(fixtureBytes('bad-nip-seller.xml'))->toJson();
}

it('accepts the report of a real invoice', function () {
    expect(reportSchemaErrors(badNipSellerJson()))->toBe([]);
});

it('rejects a report that breaks format 1.1', function (string $search, string $replace) {
    $json = badNipSellerJson();

    expect($json)->toContain($search)
        ->and(reportSchemaErrors(str_replace($search, $replace, $json)))->not->toBe([]);
})->with([
    'format 1.0' => ['"schemaVersion":"1.1"', '"schemaVersion":"1.0"'],
    'unknown key' => ['"ruleset":', '"extra":true,"ruleset":'],
    'four layers' => [',{"id":"amounts","status":"passed"}', ''],
    'layers out of order' => ['{"id":"security","status":"passed"},{"id":"recognition","status":"passed"}', '{"id":"recognition","status":"passed"},{"id":"security","status":"passed"}'],
    'unknown layer status' => ['{"id":"security","status":"passed"}', '{"id":"security","status":"skipped"}'],
    'unsupported schema layer' => ['{"id":"schema","status":"passed"}', '{"id":"schema","status":"unsupported"}'],
    'finding outside every layer' => ['"stage":"rules","title"', '"stage":null,"title"'],
    'finding without title' => ['"title":"Błędny NIP",', ''],
    'numeric param' => ['"expected":"1"', '"expected":1'],
    'params as a list' => ['"params":{"label":"NIP sprzedawcy","value":"1111111112","expected":"1"}', '"params":[]'],
    'skipped check without stage' => ['"stage":null,"message"', '"message"'],
]);
