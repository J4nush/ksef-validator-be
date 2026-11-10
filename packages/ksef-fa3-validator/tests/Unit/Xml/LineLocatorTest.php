<?php

declare(strict_types=1);

use KsefFa3\Xml\LineLocator;

covers(LineLocator::class);

it('counts the lines before an offset', function (string $bytes, int $offset, int $line) {
    expect(LineLocator::lineAt($bytes, $offset))->toBe($line);
})->with([
    'start of file' => ["a\nb", 0, 1],
    'end of the first line' => ["a\nb", 1, 1],
    'start of the second line' => ["a\nb", 2, 2],
    'windows line endings' => ["a\r\nb\r\nc", 6, 3],
    'empty input' => ['', 0, 1],
    'end of input' => ["a\n", 2, 2],
]);
