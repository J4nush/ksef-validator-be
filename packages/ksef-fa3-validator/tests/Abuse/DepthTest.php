<?php

declare(strict_types=1);

use KsefFa3\Report\Result;
use KsefFa3\Stage\ParseStage;

covers(ParseStage::class);

function nested(int $depth): string
{
    return str_repeat('<a>', $depth).str_repeat('</a>', $depth);
}

it('rejects documents nested deeper than the libxml default', function (int $depth) {
    $started = hrtime(true);
    $report = validateBytes(nested($depth));
    $seconds = (hrtime(true) - $started) / 1e9;

    expect($report->result())->toBe(Result::Errors)
        ->and(findingIds($report))->toBe(['XML-MALFORMED'])
        ->and($report->findings[0]->line)->toBe(1)
        ->and($seconds)->toBeLessThan(1.0);
})->with([258, 300, 10_000]);

it('accepts the deepest nesting libxml allows without the huge option', function () {
    expect(findingIds(validateBytes(nested(257))))->toBe(['DOC-UNSUPPORTED']);
});
