<?php

declare(strict_types=1);

use KsefFa3\Xml\FileUri;

covers(FileUri::class);

it('builds a file URI with every path segment encoded', function (string $path, string $uri) {
    expect(FileUri::fromPath($path))->toBe($uri);
})->with([
    'plain' => ['/app/resources/schemas/a.xsd', 'file:///app/resources/schemas/a.xsd'],
    'space' => ['/tmp/schemas copy/a.xsd', 'file:///tmp/schemas%20copy/a.xsd'],
    'reserved characters' => ['/tmp/a#b?c&d;e%f (1)/x.xsd', 'file:///tmp/a%23b%3Fc%26d%3Be%25f%20%281%29/x.xsd'],
    'non ASCII' => ['/tmp/zażółć/x.xsd', 'file:///tmp/za%C5%BC%C3%B3%C5%82%C4%87/x.xsd'],
]);
