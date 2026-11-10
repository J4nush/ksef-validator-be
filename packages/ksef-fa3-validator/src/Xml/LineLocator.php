<?php

declare(strict_types=1);

namespace KsefFa3\Xml;

final class LineLocator
{
    public static function lineAt(string $bytes, int $offset): int
    {
        return substr_count($bytes, "\n", 0, $offset) + 1;
    }
}
