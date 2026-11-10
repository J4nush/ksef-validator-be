<?php

declare(strict_types=1);

use KsefFa3\Report\Result;
use KsefFa3\Stage\PreflightStage;

covers(PreflightStage::class);

function billionLaughs(): string
{
    $entities = '<!ENTITY lol0 "lol">';

    for ($level = 1; $level < 10; $level++) {
        $previous = '&lol'.($level - 1).';';
        $entities .= "<!ENTITY lol{$level} \"".str_repeat($previous, 10).'">';
    }

    return "<?xml version=\"1.0\"?>\n<!DOCTYPE lolz [{$entities}]>\n<lolz>&lol9;</lolz>";
}

it('stops on a DOCTYPE', function (string $bytes, array $expected) {
    $report = validateBytes($bytes);

    expect($report->result())->toBe(Result::Errors)
        ->and(findingIds($report))->toBe($expected);
})->with([
    'XXE' => ['<!DOCTYPE a [<!ENTITY x SYSTEM "file:///etc/passwd">]><a>&x;</a>', ['SEC-DOCTYPE']],
    'billion laughs' => [billionLaughs(), ['SEC-DOCTYPE']],
    'lower case' => ['<!doctype a><a/>', ['SEC-DOCTYPE']],
    'mixed case' => ['<!DocType a><a/>', ['SEC-DOCTYPE']],
    'after a comment' => ["<?xml version=\"1.0\"?>\n<!-- x -->\n<!DOCTYPE a><a/>", ['SEC-DOCTYPE']],
    'after a BOM' => ["\xEF\xBB\xBF<!DOCTYPE a><a/>", ['TECH-UTF8-BOM', 'SEC-DOCTYPE']],
    'inside the root element' => ['<a><!DOCTYPE b></a>', ['SEC-DOCTYPE']],
]);

it('stops on a DOCTYPE before reporting discouraged characters', function () {
    expect(findingIds(validateBytes("<!DOCTYPE a><a>\u{0080}</a>")))->toBe(['SEC-DOCTYPE']);
});
