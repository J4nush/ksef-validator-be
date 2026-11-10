<?php

declare(strict_types=1);

namespace KsefFa3\Xml;

use LibXMLError;

final class LibxmlGuard
{
    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return array{0: T, 1: list<LibXMLError>}
     */
    public static function run(callable $operation, string $allowedDirectory): array
    {
        $root = realpath($allowedDirectory);
        $previousLoader = libxml_get_external_entity_loader();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        libxml_set_external_entity_loader(
            static fn (?string $publicId, string $systemId): ?string => self::resolve($systemId, $root),
        );

        try {
            $result = $operation();

            return [$result, libxml_get_errors()];
        } finally {
            libxml_use_internal_errors($previous);
            libxml_clear_errors();
            libxml_set_external_entity_loader($previousLoader);
        }
    }

    private static function resolve(string $systemId, string|false $root): ?string
    {
        if ($root === false) {
            return null;
        }

        $path = str_starts_with($systemId, FileUri::SCHEME)
            ? rawurldecode(substr($systemId, strlen(FileUri::SCHEME)))
            : $systemId;

        $resolved = realpath($path);

        // An encoded URI keeps the base of relative includes intact when the path has reserved characters.
        return $resolved !== false && str_starts_with($resolved, $root.DIRECTORY_SEPARATOR) ? FileUri::fromPath($resolved) : null;
    }
}
