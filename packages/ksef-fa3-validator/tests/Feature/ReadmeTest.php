<?php

declare(strict_types=1);

use KsefFa3\Report\Report;

covers(Report::class);

function readmeJsonExample(): mixed
{
    $readme = (string) file_get_contents(dirname(__DIR__, 2).'/README.md');
    preg_match('/^## Report format\n.*?```json\n(?<json>.*?)\n```/ms', $readme, $match);

    return json_decode($match['json'] ?? '', true, flags: JSON_THROW_ON_ERROR);
}

it('shows the JSON report of bad-nip-seller.xml in the README', function () {
    $json = validateAt(fixtureBytes('bad-nip-seller.xml'))->toJson();

    expect(readmeJsonExample())->toBe(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
});
