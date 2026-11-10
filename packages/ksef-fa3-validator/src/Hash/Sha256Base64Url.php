<?php

declare(strict_types=1);

namespace KsefFa3\Hash;

final class Sha256Base64Url
{
    public static function of(string $bytes): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $bytes, true)), '+/', '-_'), '=');
    }
}
