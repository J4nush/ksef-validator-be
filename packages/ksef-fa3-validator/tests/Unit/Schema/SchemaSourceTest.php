<?php

declare(strict_types=1);

use KsefFa3\Schema\SchemaSource;

covers(SchemaSource::class);

function schemaSourceFile(string $json): string
{
    $path = sys_get_temp_dir().'/ksef-source-'.bin2hex(random_bytes(4)).'.json';
    file_put_contents($path, $json);

    return $path;
}

it('reads the shipped schema source', function () {
    $source = SchemaSource::fromJsonFile(schemaPath('source.json'));

    expect($source->ksefApiCommit)->toBe('ce3670ea042de380039de4c5849fd6455811b292')
        ->and($source->watchedPaths)->toHaveKey('limity/limity.md', '93b843d5def041f69fe2a26d0d90a53e9fa9987a');
});

it('refuses a path that is not a readable file', function (string $path) {
    set_error_handler(function (int $level, string $message): never {
        throw new ErrorException($message, 0, $level);
    });

    try {
        expect(fn () => SchemaSource::fromJsonFile($path))
            ->toThrow(UnexpectedValueException::class, "Cannot read schema source {$path}.");
    } finally {
        restore_error_handler();
    }
})->with([
    'missing' => fn () => schemaPath('missing.json'),
    'directory' => fn () => schemaPath('bazowe'),
]);

it('refuses an incomplete or invalid source file', function (string $json, string $message) {
    $path = schemaSourceFile($json);

    try {
        expect(fn () => SchemaSource::fromJsonFile($path))->toThrow(UnexpectedValueException::class, $message);
    } finally {
        unlink($path);
    }
})->with([
    'not an object' => ['"text"', 'is incomplete'],
    'missing commit' => ['{"xsdSha256": "a", "watchedPaths": {}}', 'is incomplete'],
    'missing checksum' => ['{"ksefApiCommit": "a", "watchedPaths": {}}', 'is incomplete'],
    'missing watched paths' => ['{"ksefApiCommit": "a", "xsdSha256": "b"}', 'is incomplete'],
    'numeric watched commit' => ['{"ksefApiCommit": "a", "xsdSha256": "b", "watchedPaths": {"plik.md": 1}}', 'has an invalid watched path'],
    'watched paths as a list' => ['{"ksefApiCommit": "a", "xsdSha256": "b", "watchedPaths": ["c"]}', 'has an invalid watched path'],
]);

it('refuses a source file that is not JSON', function () {
    $path = schemaSourceFile('{');

    try {
        expect(fn () => SchemaSource::fromJsonFile($path))->toThrow(JsonException::class);
    } finally {
        unlink($path);
    }
});
