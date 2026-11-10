<?php

declare(strict_types=1);

use KsefFa3\Rule\SizeWithoutAttachmentRule;

covers(SizeWithoutAttachmentRule::class);

it('limits a file without an attachment to 1 000 000 bytes', function (string $inner, int $size, array $expected) {
    $xml = fa3Xml($inner);
    $bytes = str_pad($xml, $size);

    expect(strlen($bytes))->toBe($size)
        ->and(violationSummary(violationsOf(new SizeWithoutAttachmentRule, $xml, $bytes)))->toBe($expected);
})->with([
    'at the limit' => ['<Naglowek/>', 1_000_000, []],
    'one byte over' => ['<Naglowek/>', 1_000_001, [['TECH-SIZE-WITHOUT-ATTACHMENT', null, null]]],
    'over with an attachment' => ['<Naglowek/><Zalacznik/>', 1_000_001, []],
]);

it('ignores a small document without an attachment', function () {
    expect(violationsOf(new SizeWithoutAttachmentRule, fa3Xml('<Naglowek/>')))->toBe([]);
});
