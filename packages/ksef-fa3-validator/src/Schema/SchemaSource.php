<?php

declare(strict_types=1);

namespace KsefFa3\Schema;

use UnexpectedValueException;

final readonly class SchemaSource
{
    /**
     * @param  array<string, string>  $watchedPaths
     */
    public function __construct(
        public string $ksefApiCommit,
        public string $xsdSha256,
        public array $watchedPaths,
    ) {}

    public static function fromJsonFile(string $path): self
    {
        $json = is_file($path) && is_readable($path) ? file_get_contents($path) : false;

        if ($json === false) {
            throw new UnexpectedValueException("Cannot read schema source {$path}.");
        }

        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data)
            || ! is_string($data['ksefApiCommit'] ?? null)
            || ! is_string($data['xsdSha256'] ?? null)
            || ! is_array($data['watchedPaths'] ?? null)) {
            throw new UnexpectedValueException("Schema source file {$path} is incomplete.");
        }

        $watchedPaths = [];

        foreach ($data['watchedPaths'] as $file => $commit) {
            if (! is_string($file) || ! is_string($commit)) {
                throw new UnexpectedValueException("Schema source file {$path} has an invalid watched path.");
            }

            $watchedPaths[$file] = $commit;
        }

        return new self($data['ksefApiCommit'], $data['xsdSha256'], $watchedPaths);
    }
}
