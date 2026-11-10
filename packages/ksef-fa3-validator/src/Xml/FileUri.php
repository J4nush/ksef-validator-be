<?php

declare(strict_types=1);

namespace KsefFa3\Xml;

final class FileUri
{
    public const string SCHEME = 'file://';

    public static function fromPath(string $absolutePath): string
    {
        return self::SCHEME.implode('/', array_map(rawurlencode(...), explode('/', $absolutePath)));
    }
}
